<?php
declare(strict_types=1);

namespace DiskQuota\Listener;

use Laminas\ServiceManager\ServiceLocatorInterface;

/**
 * Blocks site item assignment (site admin > Resources) that would exceed the site quota.
 *
 * Core's UpdateSiteItems job writes item_site with SQL, so no API event sees it; the
 * resources action is its only dispatcher in core and is checked before it runs.
 *
 * Note: the check runs when the job is queued, not when it writes. Two assignments to the
 * same site submitted before the first job finishes can overshoot by one assignment; the
 * next one sees the real usage. A lock spanning the request and the core job would be needed.
 */
class SiteResourcesListener
{
    private $services;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
    }

    public function checkItemAssignment($event)
    {
        $controller = $event->getTarget();
        $request = $event->getRequest();
        if ($event->getRouteMatch()->getParam('action') !== 'resources' || !$request->isPost()) {
            return null;
        }
        $action = $request->getPost('item_assignment_action');
        if (!in_array($action, ['add', 'replace'], true)) {
            return null;
        }
        $siteId = (int) $controller->currentSite()->id();
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        if ($manager->getSiteQuota($siteId) <= 0) {
            return null;
        }
        parse_str((string) $request->getPost('item_pool', ''), $query);
        $itemIds = $this->searchItemIds($query);
        // Replace unassigns every item first, so the result is the pool alone.
        $bytes = $action === 'add'
            ? $this->getOriginalBytes($itemIds, $siteId)
            : $this->getOriginalBytes($itemIds) - $manager->getUsedDiskSpaceBySite($siteId);
        if ($bytes <= 0 || !$manager->isSiteQuotaExceeded($siteId, $bytes)) {
            return null;
        }
        $controller->messenger()->addError(
            'Item assignment rejected: the items would exceed the site disk quota.' // @translate
        );
        return $controller->redirect()->refresh();
    }

    /**
     * Resolve the pool like UpdateSiteItems does: the job grants view-all, so it also assigns
     * private items the submitting user cannot see. Grant it only around this search.
     */
    private function searchItemIds(array $query): array
    {
        $acl = $this->services->get('Omeka\Acl');
        $api = $this->services->get('Omeka\ApiManager');
        if ($acl->isAllowed(null, 'Omeka\Entity\Resource', 'view-all')) {
            return $api->search('items', $query, ['returnScalar' => 'id'])->getContent();
        }
        $acl->allow(null, 'Omeka\Entity\Resource', 'view-all');
        try {
            return $api->search('items', $query, ['returnScalar' => 'id'])->getContent();
        } finally {
            $acl->removeAllow(null, 'Omeka\Entity\Resource', 'view-all');
        }
    }

    private function getOriginalBytes(array $itemIds, ?int $exceptSiteId = null): int
    {
        $connection = $this->services->get('Omeka\Connection');
        $bytes = 0;
        foreach (array_chunk($itemIds, 1000) as $chunk) {
            $sql = 'SELECT COALESCE(SUM(m.size), 0) FROM media m WHERE m.has_original = 1 AND m.item_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ')';
            if ($exceptSiteId !== null) {
                $sql .= ' AND m.item_id NOT IN (SELECT item_id FROM item_site WHERE site_id = ?)';
                $chunk[] = $exceptSiteId;
            }
            $stmt = $connection->prepare($sql);
            foreach (array_values($chunk) as $position => $value) {
                $stmt->bindValue($position + 1, (int) $value);
            }
            $stmt->execute();
            $bytes += (int) $stmt->fetchColumn();
        }
        return $bytes;
    }
}

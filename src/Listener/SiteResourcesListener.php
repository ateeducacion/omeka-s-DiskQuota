<?php
declare(strict_types=1);

namespace DiskQuota\Listener;

use Laminas\ServiceManager\ServiceLocatorInterface;

/**
 * Blocks site item assignment (site admin > Resources) that would exceed the site quota.
 *
 * Core's UpdateSiteItems job writes item_site with SQL, so no API event sees it; the
 * resources action is its only dispatcher in core and is checked before it runs.
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
        parse_str((string) $request->getPost('item_pool', ''), $query);
        $itemIds = $this->services->get('Omeka\ApiManager')
            ->search('items', $query, ['returnScalar' => 'id'])->getContent();
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
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

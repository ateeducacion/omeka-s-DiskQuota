<?php
declare(strict_types=1);

namespace DiskQuota\Service;

use Laminas\ServiceManager\ServiceLocatorInterface;

class SiteMembership
{
    private $services;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
    }

    public function getSiteIds(array $data, $entity): array
    {
        $siteIds = [];
        $itemId = $data['o:item']['o:id'] ?? null;
        // MediaAdapter honors an explicit o:item, even when ItemAdapter set a parent.
        $item = $entity && method_exists($entity, 'getItem') ? $entity->getItem() : null;
        if ($item && ($itemId === null || $itemId == $item->getId())) {
            $siteIds = $this->getHydratedSiteIds($item);
            if ($siteIds !== null) {
                return $siteIds;
            }
            // Clean ORM collections may predate a concurrent writer.
            $itemId = $item->getId();
        }
        if (!$itemId) {
            return $siteIds;
        }
        return $this->getPersistedSiteIds($itemId);
    }

    private function getHydratedSiteIds($item): ?array
    {
        $sites = $item->getSites();
        if ($item->getId() !== null && is_object($sites) && method_exists($sites, 'isDirty') && !$sites->isDirty()) {
            return null;
        }
        $siteIds = [];
        foreach ($sites as $site) {
            $siteIds[] = (int) $site->getId();
        }
        return $siteIds;
    }

    private function getPersistedSiteIds($itemId): array
    {
        $siteIds = [];
        try {
            $connection = $this->services->get('Omeka\Connection');
            $stmt = $connection->prepare('SELECT site_id FROM item_site WHERE item_id = ?');
            $stmt->bindValue(1, $itemId);
            $stmt->execute();
            while (($siteId = $stmt->fetchColumn()) !== false) {
                $siteIds[] = (int) $siteId;
            }
        } catch (\Exception $e) {
            error_log('DiskQuota: Error determining site for item: ' . $e->getMessage());
            throw $e;
        }
        return $siteIds;
    }
}

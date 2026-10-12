<?php
declare(strict_types=1);

namespace DiskQuota\Listener;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Stdlib\Message;

/**
 * Rejects adding an existing item to a site that cannot hold its originals.
 *
 * Runs after ItemAdapter hydrated both sites and media, so the final media collection holds
 * the kept originals plus the files ingested in this same request (removed media are gone).
 * An added site gains all of them at once, even if each new file fit on its own at ingest.
 *
 * Note: each item is checked against persisted usage only, so a batch that adds several items
 * to one site in a single request can overshoot by that batch. Core's UpdateSiteItems job
 * writes item_site with SQL and is not covered here.
 */
class SiteAssignmentListener
{
    private $services;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
    }

    public function check($event): void
    {
        $errors = $event->getParam('errorStore');
        $item = $event->getParam('entity');
        if ($errors->hasErrors() || !$this->sitesMayHaveChanged($item)) {
            return;
        }
        $added = array_diff($this->getSiteIds($item), $this->getPersistedSiteIds((int) $item->getId()));
        $bytes = $this->getOriginalBytes($item);
        if (!$added || $bytes <= 0) {
            return;
        }
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        foreach ($added as $siteId) {
            if ($manager->isSiteQuotaExceeded($siteId, $bytes)) {
                $errors->addError('o:site', new Message(
                    'Site assignment rejected: quota exceeded for site %s.', // @translate
                    $siteId
                ));
            }
        }
    }

    /** New items are left to the ingest check; a clean collection was not hydrated. */
    private function sitesMayHaveChanged($item): bool
    {
        $sites = $item->getSites();
        return $item->getId() !== null && !(method_exists($sites, 'isDirty') && !$sites->isDirty());
    }

    private function getSiteIds($item): array
    {
        $siteIds = [];
        foreach ($item->getSites() as $site) {
            $siteIds[] = (int) $site->getId();
        }
        return $siteIds;
    }

    private function getPersistedSiteIds(int $itemId): array
    {
        $stmt = $this->services->get('Omeka\Connection')->prepare('SELECT site_id FROM item_site WHERE item_id = ?');
        $stmt->bindValue(1, $itemId);
        $stmt->execute();
        $siteIds = [];
        while (($siteId = $stmt->fetchColumn()) !== false) {
            $siteIds[] = (int) $siteId;
        }
        return $siteIds;
    }

    private function getOriginalBytes($item): int
    {
        $bytes = 0;
        foreach ($item->getMedia() as $media) {
            if ($media->hasOriginal()) {
                $bytes += (int) $media->getSize();
            }
        }
        return $bytes;
    }
}

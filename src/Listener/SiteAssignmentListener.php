<?php
declare(strict_types=1);

namespace DiskQuota\Listener;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Stdlib\Message;

/**
 * Rejects adding an existing item to a site that cannot hold its stored originals.
 *
 * New files are checked on media.ingest_file.pre against the item's final sites; this
 * covers the bytes the item already has. ponytail: each item is checked against persisted
 * usage only, so a batch that adds several items to one site in a single request can overshoot
 * by that batch; core's UpdateSiteItems job writes item_site with SQL and is not covered.
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
        $sites = $item->getSites();
        if ($errors->hasErrors() || $item->getId() === null
            || (method_exists($sites, 'isDirty') && !$sites->isDirty())) {
            return;
        }
        $stmt = $this->services->get('Omeka\Connection')->prepare('SELECT site_id FROM item_site WHERE item_id = ?');
        $stmt->bindValue(1, $item->getId());
        $stmt->execute();
        $persisted = [];
        while (($siteId = $stmt->fetchColumn()) !== false) {
            $persisted[] = (int) $siteId;
        }
        $added = [];
        foreach ($sites as $site) {
            if (!in_array((int) $site->getId(), $persisted, true)) {
                $added[] = (int) $site->getId();
            }
        }
        $bytes = 0;
        foreach ($item->getMedia() as $media) {
            if ($media->getId() !== null && $media->hasOriginal()) {
                $bytes += (int) $media->getSize();
            }
        }
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
}

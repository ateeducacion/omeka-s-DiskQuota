<?php
declare(strict_types=1);

namespace DiskQuota\Listener;

use DiskQuota\Service\PendingUploads;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Stdlib\Message;

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
        if ($errors->hasErrors()) {
            $this->services->get(QuotaWriteListener::class)->failed($event->getParam('request'));
            return;
        }
        $item = $event->getParam('entity');
        $pending = $this->services->get(PendingUploads::class);
        $deltas = $this->getSiteDeltas($item);
        $pending->setSiteChanges($item, $deltas);
        $siteIds = [];
        foreach ($deltas as $siteId => $bytes) {
            if ($bytes > 0) {
                $siteIds[] = $siteId;
            }
        }
        [, $siteBytes] = $pending->getUploadBytes(null, null, 0, $siteIds);
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        foreach ($siteBytes as $siteId => $bytes) {
            if ($manager->isSiteQuotaExceeded($siteId, $bytes)) {
                $errors->addError('o:site', new Message(
                    'Site assignment rejected: quota exceeded for site %s.', // @translate
                    $siteId
                ));
            }
        }
        if ($errors->hasErrors()) {
            $pending->rejectSiteChanges($item);
            $this->services->get(QuotaWriteListener::class)->failed($event->getParam('request'));
        }
    }

    private function getSiteDeltas($item): array
    {
        $deltas = [];
        $originalBytes = 0;
        if ($item->getId() !== null) {
            $connection = $this->services->get('Omeka\Connection');
            $originalBytes = (int) $connection->fetchOne(
                'SELECT COALESCE(SUM(size), 0) FROM media WHERE item_id = ? AND has_original = 1',
                [$item->getId()]
            );
            $rows = $connection->fetchAll('SELECT site_id FROM item_site WHERE item_id = ?', [$item->getId()]);
            foreach ($rows as $row) {
                $deltas[(int) $row['site_id']] = -$originalBytes;
            }
        }
        $retainedBytes = 0;
        foreach ($item->getMedia() as $media) {
            if ($media->getId() !== null && $media->hasOriginal()) {
                $retainedBytes += (int) $media->getSize();
            }
        }
        foreach ($item->getSites() as $site) {
            $siteId = (int) $site->getId();
            $deltas[$siteId] = ($deltas[$siteId] ?? 0) + $retainedBytes;
        }
        return array_filter($deltas);
    }
}

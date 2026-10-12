<?php
declare(strict_types=1);

namespace DiskQuota\Listener;

use DiskQuota\Service\PendingUploads;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Exception\ValidationException;
use Omeka\Stdlib\Message;

class UploadQuotaListener
{
    private $services;
    private $pending;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
        $this->pending = new PendingUploads($services);
    }

    public function checkIngestFileQuota($event): void
    {
        if ($event->getParam('request')->getOperation() !== 'create') {
            return;
        }
        if (!$event->getParam('storeOriginal')) {
            $this->pending->track($event->getTarget(), 0);
            return;
        }
        $size = (int) $event->getParam('tempFile')->getSize();
        if ($size <= 0) {
            $this->pending->track($event->getTarget(), 0);
            return;
        }
        $media = $event->getTarget();
        $ownerId = $media->getOwner()?->getId();
        [$userBytes, $siteBytes] = $this->pending->getUploadBytes(
            $media,
            $ownerId,
            $size,
            $this->getSiteIds($media)
        );
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        if ($ownerId !== null && $manager->isQuotaExceeded($ownerId, $userBytes)) {
            $this->rejectUserUpload($event, $ownerId, $size, $userBytes - $size);
        }
        foreach ($siteBytes as $siteId => $bytes) {
            if ($manager->isSiteQuotaExceeded($siteId, $bytes)) {
                $this->rejectSiteUpload($event, $siteId, $size, $bytes - $size);
            }
        }
        $errors = $event->getParam('errorStore');
        if ($errors->hasErrors()) {
            // This event does not inspect errorStore before copying to permanent storage.
            $exception = new ValidationException();
            $exception->setErrorStore($errors);
            throw $exception;
        }
        $this->pending->track($media, $size);
    }

    public function cleanupFailedHydration($event): void
    {
        $this->pending->cleanupFailedHydration($event);
    }

    public function cleanupUnpersistedFiles(): void
    {
        $this->pending->cleanupUnpersistedFiles();
    }

    private function getSiteIds($media): array
    {
        $siteIds = [];
        // MediaAdapter sets the final item before ingesting, including pending site assignments.
        foreach ($media->getItem()?->getSites() ?? [] as $site) {
            $siteIds[] = (int) $site->getId();
        }
        return $siteIds;
    }

    private function rejectUserUpload($event, $userId, int $size, int $pendingBytes): void
    {
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        $quotaMB = round($manager->getUserQuota($userId) / (1024 * 1024), 2);
        $usedMB = round(($manager->getUsedDiskSpaceByUser($userId) + $pendingBytes) / (1024 * 1024), 2);
        $sizeMB = round($size / (1024 * 1024), 2);
        error_log(sprintf(
            'DiskQuota: Upload rejected. File size: %s MB, Used: %s MB, Limit: %s MB',
            $sizeMB,
            $usedMB,
            $quotaMB
        ));
        $errors = $event->getParam('errorStore');
        if ($errors) {
            $errors->addError('file', new Message(
                'Upload rejected: quota exceeded. File: %s MB, Used: %s MB, Limit: %s MB',
                $sizeMB,
                $usedMB,
                $quotaMB
            ));
        }
    }

    private function rejectSiteUpload($event, int $siteId, int $size, int $pendingBytes): void
    {
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        $quotaMB = round($manager->getSiteQuota($siteId) / (1024 * 1024), 2);
        $usedMB = round(($manager->getUsedDiskSpaceBySite($siteId) + $pendingBytes) / (1024 * 1024), 2);
        $sizeMB = round($size / (1024 * 1024), 2);
        error_log(sprintf(
            'DiskQuota: Upload rejected for site %d. File size: %s MB, Used: %s MB, Limit: %s MB',
            $siteId,
            $sizeMB,
            $usedMB,
            $quotaMB
        ));
        $errors = $event->getParam('errorStore');
        if ($errors) {
            $errors->addError('file', new Message(
                'Upload rejected: site quota exceeded. File: %s MB, Used: %s MB, Limit: %s MB',
                $sizeMB,
                $usedMB,
                $quotaMB
            ));
        }
    }
}

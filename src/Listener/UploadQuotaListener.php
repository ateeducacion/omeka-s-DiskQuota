<?php
declare(strict_types=1);

namespace DiskQuota\Listener;

use DiskQuota\Service\PendingUploads;
use DiskQuota\Service\SiteMembership;
use DiskQuota\Service\UploadSizeResolver;
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
        $this->pending = $services->get(PendingUploads::class);
    }

    public function checkSiteQuotaBeforeUpload($event): void
    {
        $request = $event->getParam('request');
        if ($request->getOperation() !== 'create') {
            return;
        }
        $data = $request->getContent();
        if (isset($data['store_original']) && !$data['store_original']) {
            return;
        }
        $size = $this->getFileSize($data);
        if ($size <= 0) {
            return;
        }
        $siteIds = $this->services->get(SiteMembership::class)->getSiteIds($data, $event->getParam('entity'));
        if (!$siteIds) {
            return;
        }
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        [, $siteBytes] = $this->pending->getUploadBytes($event->getParam('entity'), null, $size, $siteIds);
        foreach ($siteBytes as $siteId => $bytes) {
            if ($manager->isSiteQuotaExceeded($siteId, $bytes)) {
                $this->rejectSiteUpload($event, $siteId, $bytes);
            }
        }
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
            $this->services->get(SiteMembership::class)->getSiteIds([], $media)
        );
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        if ($ownerId !== null && $manager->isQuotaExceeded($ownerId, $userBytes)) {
            $this->rejectUserUpload($event, $ownerId, $userBytes);
        }
        foreach ($siteBytes as $siteId => $bytes) {
            if ($manager->isSiteQuotaExceeded($siteId, $bytes)) {
                $this->rejectSiteUpload($event, $siteId, $bytes);
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

    private function getFileSize(array $data): int
    {
        return (new UploadSizeResolver())->getSize($this->services->get('Request'), $data);
    }

    private function rejectUserUpload($event, $userId, int $size): void
    {
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        $quotaMB = round($manager->getUserQuota($userId) / (1024 * 1024), 2);
        $usedMB = round($manager->getUsedDiskSpaceByUser($userId) / (1024 * 1024), 2);
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

    private function rejectSiteUpload($event, int $siteId, int $size): void
    {
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        $quotaMB = round($manager->getSiteQuota($siteId) / (1024 * 1024), 2);
        $usedMB = round($manager->getUsedDiskSpaceBySite($siteId) / (1024 * 1024), 2);
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

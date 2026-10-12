<?php
declare(strict_types=1);

namespace DiskQuota\Listener;

use DiskQuota\Service\UploadSizeResolver;
use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Exception\ValidationException;
use Omeka\Stdlib\Message;

class UploadQuotaListener
{
    private $services;
    private $pending = [];
    private $cleanupRegistered = false;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
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
        $siteIds = $this->getSiteIds($data, $event->getParam('entity'));
        if (!$siteIds) {
            return;
        }
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        foreach ($siteIds as $siteId) {
            if ($manager->isSiteQuotaExceeded($siteId, $size)) {
                $this->rejectSiteUpload($event, $siteId, $size);
            }
        }
    }

    public function checkIngestFileQuota($event): void
    {
        if ($event->getParam('request')->getOperation() !== 'create' || !$event->getParam('storeOriginal')) {
            return;
        }
        $size = (int) $event->getParam('tempFile')->getSize();
        if ($size <= 0) {
            $this->trackPendingFile($event->getTarget(), 0);
            return;
        }
        $media = $event->getTarget();
        $owner = $media->getOwner();
        $ownerId = $owner ? $owner->getId() : null;
        $userBytes = $size;
        $siteBytes = array_fill_keys($this->getSiteIds([], $media), $size);
        // shortcut: pending bytes are request-local; shared reservations are needed for concurrent uploads.
        foreach ($this->pending as $key => $entry) {
            $pending = $entry['media'];
            if ($pending->getId() !== null) {
                unset($this->pending[$key]);
                continue;
            }
            if ($pending === $media) {
                continue;
            }
            $pendingOwner = $pending->getOwner();
            if ($ownerId !== null && $pendingOwner && $pendingOwner->getId() === $ownerId) {
                $userBytes += $entry['size'];
            }
            foreach ($this->getSiteIds([], $pending) as $siteId) {
                if (isset($siteBytes[$siteId])) {
                    $siteBytes[$siteId] += $entry['size'];
                }
            }
        }
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
        $this->trackPendingFile($media, $size);
    }

    private function trackPendingFile($media, int $size): void
    {
        $this->pending[spl_object_id($media)] = ['media' => $media, 'size' => $size];
        if (!$this->cleanupRegistered) {
            register_shutdown_function([$this, 'cleanupUnpersistedFiles']);
            $this->cleanupRegistered = true;
        }
    }

    public function cleanupFailedHydration($event): void
    {
        if (!$event->getParam('errorStore')->hasErrors()) {
            return;
        }
        $entity = $event->getParam('entity');
        $media = method_exists($entity, 'getMedia') ? $entity->getMedia() : [$entity];
        foreach ($media as $file) {
            $key = spl_object_id($file);
            if (isset($this->pending[$key]) && $file->getId() === null) {
                $this->discardPendingFile($key);
                if (is_object($media) && method_exists($media, 'removeElement')) {
                    $media->removeElement($file);
                }
            }
        }
    }

    public function cleanupUnpersistedFiles(): void
    {
        foreach ($this->pending as $key => $entry) {
            if ($entry['media']->getId() === null) {
                $this->discardPendingFile($key);
            }
        }
        $this->pending = [];
    }

    private function discardPendingFile(int $key): void
    {
        $media = $this->pending[$key]['media'];
        $this->services->get('ModuleManager')->getModule('Omeka')->deleteMediaFiles(new Event('cleanup', $media));
        if ($this->services->has('Omeka\EntityManager')) {
            $this->services->get('Omeka\EntityManager')->detach($media);
        }
        unset($this->pending[$key]);
    }

    private function getFileSize(array $data): int
    {
        return (new UploadSizeResolver())->getSize($this->services->get('Request'), $data);
    }

    private function getSiteIds(array $data, $entity): array
    {
        $siteIds = [];
        $itemId = $data['o:item']['o:id'] ?? null;
        // MediaAdapter honors an explicit o:item, even when ItemAdapter set a parent.
        $item = $entity && method_exists($entity, 'getItem') ? $entity->getItem() : null;
        if ($item && ($itemId === null || $itemId == $item->getId())) {
            foreach ($item->getSites() as $site) {
                $siteIds[] = (int) $site->getId();
            }
            return $siteIds;
        }
        if (!$itemId) {
            return $siteIds;
        }
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
        }
        return $siteIds;
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

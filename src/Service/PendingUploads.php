<?php
declare(strict_types=1);

namespace DiskQuota\Service;

use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceLocatorInterface;

class PendingUploads
{
    private $services;
    private $pending = [];
    private $cleanupRegistered = false;
    private $siteChanges = [];
    private $continueBatch = false;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
    }

    public function getUploadBytes($media, ?int $ownerId, int $size, array $siteIds): array
    {
        $userBytes = $size;
        $siteBytes = $this->getSiteBytes($size, $siteIds);
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
            foreach ($this->services->get(SiteMembership::class)->getSiteIds([], $pending) as $siteId) {
                if (isset($siteBytes[$siteId])) {
                    $siteBytes[$siteId] += $entry['size'];
                }
            }
        }
        return [$userBytes, $siteBytes];
    }

    private function getSiteBytes(int $size, array $siteIds): array
    {
        $siteBytes = array_fill_keys($siteIds, $size);
        foreach ($this->siteChanges as $change) {
            foreach ($change['deltas'] as $siteId => $bytes) {
                if (isset($siteBytes[$siteId])) {
                    $siteBytes[$siteId] += $bytes;
                }
            }
        }
        return $siteBytes;
    }

    public function setSiteChanges($item, array $deltas): void
    {
        if (!$deltas) {
            unset($this->siteChanges[$item->getId()]);
            return;
        }
        $this->siteChanges[$item->getId()] = ['item' => $item, 'deltas' => $deltas];
    }

    public function setContinueBatch(bool $continue): void
    {
        $this->continueBatch = $continue;
    }

    public function rejectSiteChanges($item): void
    {
        unset($this->siteChanges[$item->getId()]);
        if (!$this->continueBatch) {
            $manager = $this->services->get('Omeka\EntityManager');
            foreach ($this->siteChanges as $change) {
                $manager->refresh($change['item']);
            }
            $this->clearSiteChanges();
        }
    }

    public function clearSiteChanges(): void
    {
        $this->siteChanges = [];
        $this->continueBatch = false;
    }

    public function track($media, int $size): void
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
}

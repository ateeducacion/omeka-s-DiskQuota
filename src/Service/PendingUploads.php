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

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
    }

    public function getUploadBytes($media, ?int $ownerId, int $size, array $siteIds): array
    {
        $userBytes = $size;
        $siteBytes = array_fill_keys($siteIds, $size);
        // shortcut: pending bytes are request-local; shared reservations are needed for concurrent uploads.
        foreach ($this->pending as $key => $entry) {
            $pending = $entry['media'];
            if ($pending->getId() !== null) {
                unset($this->pending[$key]);
                continue;
            }
            if ($pending === $media || $this->isAbandoned($pending, $media)) {
                continue;
            }
            $pendingOwner = $pending->getOwner();
            if ($ownerId !== null && $pendingOwner && $pendingOwner->getId() === $ownerId) {
                $userBytes += $entry['size'];
            }
            foreach ($pending->getItem()?->getSites() ?? [] as $site) {
                $siteId = $site->getId();
                if (isset($siteBytes[$siteId])) {
                    $siteBytes[$siteId] += $entry['size'];
                }
            }
        }
        return [$userBytes, $siteBytes];
    }

    /**
     * A failed operation (e.g. validateEntity after api.hydrate.post) never persists its
     * media: core skips persist on create and refreshes the item on update. Its bytes stop
     * counting; its files are only deleted at shutdown, once nothing can still persist it.
     */
    private function isAbandoned($pending, $media): bool
    {
        $item = $pending->getItem();
        if (!$this->services->has('Omeka\EntityManager') || $item === $media->getItem()) {
            return false;
        }
        $entityManager = $this->services->get('Omeka\EntityManager');
        if ($entityManager->contains($pending)) {
            return false;
        }
        return !($item && $entityManager->contains($item) && $item->getMedia()->contains($pending));
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

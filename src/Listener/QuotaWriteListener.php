<?php
declare(strict_types=1);

namespace DiskQuota\Listener;

use DiskQuota\Service\PendingUploads;
use DiskQuota\Service\QuotaLock;
use Laminas\ServiceManager\ServiceLocatorInterface;

class QuotaWriteListener
{
    private $services;
    private $lock;
    private $rootRequest;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
    }

    public function begin($event): void
    {
        $request = $event->getParam('request');
        if (!in_array($request->getOperation(), ['create', 'update', 'batch_create', 'batch_update'], true)) {
            return;
        }
        if (!$this->lock) {
            $this->lock = new QuotaLock($this->services->get('Omeka\Connection'));
            register_shutdown_function([$this, 'shutdown']);
        }
        $this->lock->acquire();
        if (!$this->rootRequest) {
            $this->rootRequest = $request;
            $this->services->get(PendingUploads::class)->setContinueBatch(
                $request->getOperation() === 'batch_update' && $request->getOption('continueOnError', false)
            );
        }
    }

    public function refreshItem($event): void
    {
        $item = $event->getParam('entity');
        if ($item->getId() === null) {
            return;
        }
        foreach ([$item->getSites(), $item->getMedia()] as $collection) {
            if (method_exists($collection, 'isDirty') && $collection->isDirty()) {
                return;
            }
        }
        $this->services->get('Omeka\EntityManager')->refresh($item);
    }

    public function finish($event): void
    {
        $request = $event->getParam('request');
        if ($request !== $this->rootRequest || !$request->getOption('flushEntityManager', true)) {
            return;
        }
        if (!$this->services->get('Omeka\Connection')->isTransactionActive()) {
            $this->services->get(PendingUploads::class)->clearSiteChanges();
            $this->lock->release();
            $this->rootRequest = null;
        }
    }

    public function failed($request): void
    {
        if ($request === $this->rootRequest || ($this->rootRequest
            && $this->rootRequest->getOperation() === 'batch_update'
            && !$this->rootRequest->getOption('continueOnError', false))) {
            $this->rootRequest = null;
        }
    }

    public function shutdown(): void
    {
        if (!$this->lock) {
            return;
        }
        try {
            $this->services->get(PendingUploads::class)->cleanupUnpersistedFiles();
        } finally {
            $this->lock->release();
            $this->lock = null;
            $this->rootRequest = null;
        }
    }
}

<?php
declare(strict_types=1);

namespace DiskQuotaTest\Module;

use DiskQuota\Listener\QuotaWriteListener;
use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;

class QuotaWriteTest extends TestCase
{
    private $path;
    private $listener;
    private $probe;
    private $services;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'diskquota-write-');
        $platform = $this->getMockBuilder(\stdClass::class)->addMethods(['getName'])->getMock();
        $platform->method('getName')->willReturn('sqlite');
        $db = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getParams', 'getDatabasePlatform', 'isTransactionActive'])->getMock();
        $db->method('getParams')->willReturn(['path' => $this->path]);
        $db->method('getDatabasePlatform')->willReturn($platform);
        $db->method('isTransactionActive')->willReturn(false);
        $services = new ServiceManager((new \DiskQuota\Module())->getServiceConfig());
        $services->setService('Omeka\Connection', $db);
        $this->services = $services;
        $this->listener = $services->get(QuotaWriteListener::class);
        $this->probe = fopen($this->path . '.diskquota.lock', 'c');
    }

    protected function tearDown(): void
    {
        $this->listener->shutdown();
        fclose($this->probe);
        unlink($this->path);
        unlink($this->path . '.diskquota.lock');
    }

    public function testReadsDoNotAcquireOrReleaseAnOuterWriteLock(): void
    {
        $read = $this->event('read');
        $this->listener->begin($read);
        $this->assertUnlocked();
        $write = $this->event('create');
        $this->listener->begin($write);
        $this->listener->finish($read);
        $this->assertLocked();
        $this->listener->finish($write);
        $this->assertUnlocked();
    }

    public function testNestedWriteDoesNotReleaseBeforeItsParentFlushes(): void
    {
        $parent = $this->event('create');
        $child = $this->event('create');
        $this->listener->begin($parent);
        $this->listener->begin($child);
        $this->listener->finish($child);
        $this->assertLocked();
        $this->listener->finish($parent);
        $this->assertUnlocked();
    }

    public function testUnflushedRequestsKeepTheLockUntilShutdown(): void
    {
        $write = $this->event('create', ['flushEntityManager' => false]);
        $this->listener->begin($write);
        $this->listener->finish($write);
        $this->assertLocked();
        $this->listener->shutdown();
        $this->assertUnlocked();
    }

    public function testRejectedAssignmentRetainsLockButAllowsASuccessfulRetryToReleaseIt(): void
    {
        $write = $this->event('update');
        $this->listener->begin($write);
        $this->listener->failed($write->getParam('request'));
        $this->assertLocked();
        $retry = $this->event('update');
        $this->listener->begin($retry);
        $this->listener->finish($retry);
        $this->assertUnlocked();
    }

    public function testBatchFailureAndContinueOnErrorHaveDifferentLifetimes(): void
    {
        $batch = $this->event('batch_update', ['continueOnError' => true]);
        $child = $this->event('update', ['flushEntityManager' => false]);
        $this->listener->begin($batch);
        $this->listener->begin($child);
        $this->listener->failed($child->getParam('request'));
        $this->listener->finish($child);
        $this->assertLocked();
        $this->listener->finish($batch);
        $this->assertUnlocked();
        $abort = $this->event('batch_update');
        $this->listener->begin($abort);
        $this->listener->failed($child->getParam('request'));
        $retry = $this->event('update');
        $this->listener->begin($retry);
        $this->listener->finish($retry);
        $this->assertUnlocked();
    }

    public function testItemRefreshPreservesPendingCollections(): void
    {
        $manager = $this->getMockBuilder(\stdClass::class)->addMethods(['refresh'])->getMock();
        $manager->expects($this->once())->method('refresh');
        $this->services->setService('Omeka\EntityManager', $manager);
        foreach ([[null, false], [1, true], [1, false]] as [$id, $dirty]) {
            $collection = $this->getMockBuilder(\stdClass::class)->addMethods(['isDirty'])->getMock();
            $collection->method('isDirty')->willReturn($dirty);
            $item = $this->getMockBuilder(\stdClass::class)->addMethods(['getId', 'getSites', 'getMedia'])->getMock();
            $item->method('getId')->willReturn($id);
            $item->method('getSites')->willReturn($collection);
            $item->method('getMedia')->willReturn($collection);
            $this->listener->refreshItem(new Event('api.hydrate.pre', null, ['entity' => $item]));
        }
    }

    private function event(string $operation, array $options = []): Event
    {
        $request = $this->getMockBuilder(\stdClass::class)->addMethods(['getOperation', 'getOption'])->getMock();
        $request->method('getOperation')->willReturn($operation);
        $request->method('getOption')->willReturnCallback(fn ($key, $default) => $options[$key] ?? $default);
        return new Event('api.execute.pre', null, ['request' => $request]);
    }

    private function assertLocked(): void
    {
        $this->assertFalse(flock($this->probe, LOCK_EX | LOCK_NB));
    }

    private function assertUnlocked(): void
    {
        $this->assertTrue(flock($this->probe, LOCK_EX | LOCK_NB));
        flock($this->probe, LOCK_UN);
    }
}

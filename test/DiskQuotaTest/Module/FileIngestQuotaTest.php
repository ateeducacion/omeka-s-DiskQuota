<?php
declare(strict_types=1);

namespace DiskQuotaTest\Module;

use DiskQuota\Listener\UploadQuotaListener;
use DiskQuota\Service\DiskQuotaManager;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;
use Laminas\EventManager\SharedEventManager;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Api\Exception\ValidationException;
use Omeka\Stdlib\ErrorStore;
use PHPUnit\Framework\TestCase;

class FileIngestQuotaTest extends TestCase
{
    private $services;
    private $events;
    private $deleted;

    protected function setUp(): void
    {
        $this->deleted = [];
        $this->services = new ServiceManager((new \DiskQuota\Module())->getServiceConfig());
        $core = new class(function ($event) {
            $this->deleted[] = $event->getTarget();
        }) {
            private $delete;
            public function __construct($delete)
            {
                $this->delete = $delete;
            }
            public function deleteMediaFiles($event)
            {
                ($this->delete)($event);
            }
        };
        $modules = new class($core) {
            private $core;
            public function __construct($core)
            {
                $this->core = $core;
            }
            public function getModule($name)
            {
                return $this->core;
            }
        };
        $this->services->setService('ModuleManager', $modules);
        $module = new \DiskQuota\Module();
        $module->setServiceLocator($this->services);
        $shared = new SharedEventManager();
        $module->attachListeners($shared);
        $this->events = new EventManager($shared, ['Omeka\\File\\TempFile']);
    }

    protected function tearDown(): void
    {
        $this->services->get(UploadQuotaListener::class)->cleanupUnpersistedFiles();
    }

    /** @dataProvider quotaScopes */
    public function testRealFileSizeOverridesUntrustedMetadata(string $scope): void
    {
        $this->setQuota($scope);
        $this->expectException(ValidationException::class);
        $this->ingest($this->media(1, [10]), 101);
    }

    public function quotaScopes(): array
    {
        return [['user'], ['site']];
    }

    /** @dataProvider quotaScopes */
    public function testPendingFilesCannotExceedTheQuotaTogether(string $scope): void
    {
        $this->setQuota($scope);
        $this->ingest($this->media(1, [10]), 60);
        $this->expectException(ValidationException::class);
        $this->ingest($this->media(1, [10]), 60);
    }

    public function testPendingFilesAreScopedByOwnerAndSite(): void
    {
        $this->setQuota('both');
        $this->ingest($this->media(1, [10]), 60);
        $this->ingest($this->media(2, [20]), 60);
        $this->assertSame([], $this->deleted);
    }

    public function testSiteQuotaIncludesPendingFilesOwnedByAnotherUser(): void
    {
        $this->setQuota('site');
        $this->ingest($this->media(1, [10]), 60);
        $this->expectException(ValidationException::class);
        $this->ingest($this->media(2, [10]), 60);
    }

    public function testUserQuotaIncludesPendingFilesInAnotherSite(): void
    {
        $this->setQuota('user');
        $this->ingest($this->media(1, [10]), 60);
        $this->expectException(ValidationException::class);
        $this->ingest($this->media(1, [20]), 60);
    }

    public function testExactlyFullQuotaAndRepeatedEventAreAllowed(): void
    {
        $this->setQuota('both');
        $media = $this->media(1, [10]);
        $this->ingest($media, 60);
        $this->ingest($media, 60);
        $this->ingest($this->media(1, [10]), 40);
        $this->assertSame([], $this->deleted);
    }

    public function testPersistedFilesAreNotCountedTwiceOrDeleted(): void
    {
        $this->setQuota('both');
        $first = $this->media(1, [10], 1);
        $this->ingest($first, 60);
        $this->ingest($this->media(1, [10]), 60);
        $this->services->get(UploadQuotaListener::class)->cleanupUnpersistedFiles();
        $this->assertNotContains($first, $this->deleted);
    }

    public function testUnlimitedQuotaAllowsLargeFiles(): void
    {
        $this->setQuota('none');
        $this->ingest($this->media(1, [10]), PHP_INT_MAX);
        $this->assertSame([], $this->deleted);
    }

    public function testQuotaUsesTheFinalOwnerRatherThanTheAuthenticatedUploader(): void
    {
        $manager = $this->createMock(DiskQuotaManager::class);
        $manager->expects($this->once())->method('isQuotaExceeded')->with(2, 60)->willReturn(false);
        $this->services->setService('DiskQuota\\DiskQuotaManager', $manager);
        $auth = $this->getMockBuilder(\stdClass::class)->addMethods(['getIdentity'])->getMock();
        $auth->expects($this->never())->method('getIdentity');
        $this->services->setService('Omeka\\AuthenticationService', $auth);
        $this->ingest($this->media(2, []), 60);
    }

    public function testOwnerlessMediaStillObeysSiteQuota(): void
    {
        $this->setQuota('site');
        $this->expectException(ValidationException::class);
        $this->ingest($this->media(null, [10]), 101);
    }

    /** @dataProvider ignoredFiles */
    public function testNonOriginalAndUpdateFilesAreIgnored(string $operation, bool $original, int $bytes): void
    {
        $manager = $this->createMock(DiskQuotaManager::class);
        $manager->expects($this->never())->method('isQuotaExceeded');
        $manager->expects($this->never())->method('isSiteQuotaExceeded');
        $this->services->setService('DiskQuota\\DiskQuotaManager', $manager);
        $this->ingest($this->media(1, [10]), $bytes, $operation, $original);
        $this->assertSame([], $this->deleted);
    }

    public function ignoredFiles(): array
    {
        return [['update', true, 1000], ['create', false, 1000], ['create', true, 0]];
    }

    public function testEmptyOriginalFilesAreAlsoCleanedOnFailure(): void
    {
        $this->setQuota('none');
        $media = $this->media(1, [10]);
        $this->ingest($media, 0);
        $this->services->get(UploadQuotaListener::class)->cleanupUnpersistedFiles();
        $this->assertSame([$media], $this->deleted);
    }

    public function testThumbnailOnlyFilesAreAlsoCleanedOnFailure(): void
    {
        $this->setQuota('none');
        $media = $this->media(1, [10]);
        $this->ingest($media, 1000, 'create', false);
        $errors = new ErrorStore();
        $errors->addError('file', 'Rejected operation');
        $this->services->get(UploadQuotaListener::class)->cleanupFailedHydration(new Event('api.hydrate.post', null, [
            'entity' => $media, 'errorStore' => $errors,
        ]));
        $this->assertSame([$media], $this->deleted);
    }

    public function testFailedItemHydrationCleansOnlyItsNewFilesAndReleasesTheirBytes(): void
    {
        $this->setQuota('both');
        $failed = $this->media(1, [10]);
        $other = $this->media(2, [20]);
        $this->ingest($failed, 60);
        $this->ingest($other, 60);
        $item = $this->getMockBuilder(\stdClass::class)->addMethods(['getMedia'])->getMock();
        $item->method('getMedia')->willReturn([$failed, $this->media(1, [10], 99)]);
        $errors = new ErrorStore();
        $errors->addError('o:media', 'Rejected child');
        $this->services->get(UploadQuotaListener::class)->cleanupFailedHydration(new Event('api.hydrate.post', null, [
            'entity' => $item, 'errorStore' => $errors,
        ]));
        $this->assertSame([$failed], $this->deleted);
        $this->ingest($this->media(1, [10]), 100);
        $this->assertNotContains($other, $this->deleted);
    }

    private function setQuota(string $scope): void
    {
        $manager = $this->createMock(DiskQuotaManager::class);
        $manager->method('isQuotaExceeded')->willReturnCallback(
            fn ($id, $bytes) => in_array($scope, ['user', 'both'], true) && $bytes > 100
        );
        $manager->method('isSiteQuotaExceeded')->willReturnCallback(
            fn ($id, $bytes) => in_array($scope, ['site', 'both'], true) && $bytes > 100
        );
        $manager->method('getUserQuota')->willReturn(100);
        $manager->method('getSiteQuota')->willReturn(100);
        $manager->method('getUsedDiskSpaceByUser')->willReturn(0);
        $manager->method('getUsedDiskSpaceBySite')->willReturn(0);
        $this->services->setService('DiskQuota\\DiskQuotaManager', $manager);
    }

    private function media(?int $ownerId, array $siteIds, ?int $id = null)
    {
        $owner = null;
        if ($ownerId !== null) {
            $owner = $this->getMockBuilder(\stdClass::class)->addMethods(['getId'])->getMock();
            $owner->method('getId')->willReturn($ownerId);
        }
        $sites = [];
        foreach ($siteIds as $siteId) {
            $site = $this->getMockBuilder(\stdClass::class)->addMethods(['getId'])->getMock();
            $site->method('getId')->willReturn($siteId);
            $sites[] = $site;
        }
        $item = $this->getMockBuilder(\stdClass::class)->addMethods(['getSites', 'getId'])->getMock();
        $item->method('getSites')->willReturn($sites);
        $media = $this->getMockBuilder(\stdClass::class)->addMethods(['getId', 'getOwner', 'getItem'])->getMock();
        $media->method('getId')->willReturn($id);
        $media->method('getOwner')->willReturn($owner);
        $media->method('getItem')->willReturn($item);
        return $media;
    }

    private function ingest($media, int $size, string $operation = 'create', bool $original = true): void
    {
        $file = $this->getMockBuilder(\stdClass::class)->addMethods(['getSize'])->getMock();
        $file->method('getSize')->willReturn($size);
        $request = $this->getMockBuilder(\stdClass::class)->addMethods(['getOperation', 'getContent'])->getMock();
        $request->method('getOperation')->willReturn($operation);
        $request->method('getContent')->willReturn(['o:ingester' => 'url', 'o:size' => 1]);
        $errors = new ErrorStore();
        $this->events->triggerEvent(new Event('media.ingest_file.pre', $media, [
            'request' => $request, 'tempFile' => $file, 'storeOriginal' => $original, 'errorStore' => $errors,
        ]));
        $this->assertFalse($errors->hasErrors());
    }
}

<?php
declare(strict_types=1);

namespace DiskQuotaTest\Module;

use DiskQuota\Listener\UploadQuotaListener;
use DiskQuota\Service\DiskQuotaManager;
use DiskQuotaTest\SiteFixture;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;
use Laminas\EventManager\SharedEventManager;
use Laminas\Http\Request;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Stdlib\Parameters;
use PHPUnit\Framework\TestCase;

class SiteMembershipTest extends TestCase
{
    use SiteFixture;

    public function testRegisteredHydrationListenerRejectsDirectUploads(): void
    {
        $services = new ServiceManager((new \DiskQuota\Module())->getServiceConfig());
        $services->setService('Omeka\\Connection', $this->createSiteDatabase());
        $services->setService('Request', new Request());
        $services->setService('Omeka\\AuthenticationService', new class {
            public function getIdentity()
            {
                return null;
            }
        });
        $manager = $this->createMock(DiskQuotaManager::class);
        $manager->expects($this->once())->method('isSiteQuotaExceeded')->with(30, 100)->willReturn(true);
        $manager->method('getSiteQuota')->willReturn(1000);
        $manager->method('getUsedDiskSpaceBySite')->willReturn(950);
        $services->setService('DiskQuota\\DiskQuotaManager', $manager);
        $errors = $this->getMockBuilder(\stdClass::class)->addMethods(['addError'])->getMock();
        $errors->expects($this->once())->method('addError')->with('file', $this->anything());
        $module = new \DiskQuota\Module();
        $module->setServiceLocator($services);
        $shared = new SharedEventManager();
        $module->attachListeners($shared);
        $events = new EventManager($shared, ['Omeka\\Api\\Adapter\\MediaAdapter']);
        $event = $this->uploadEvent(4, $errors);
        $event->setName('api.hydrate.pre');
        $events->triggerEvent($event);
    }

    /** @dataProvider nestedUploadCases */
    public function testNestedUploadChecksTheFinalItemSites(?int $parentId, ?int $requestedId, int $expectedSite): void
    {
        $site = $this->getMockBuilder(\stdClass::class)->addMethods(['getId'])->getMock();
        $site->method('getId')->willReturn(20);
        $item = $this->getMockBuilder(\stdClass::class)->addMethods(['getId', 'getSites'])->getMock();
        $item->method('getId')->willReturn($parentId);
        $item->method('getSites')->willReturn([$site]);
        $media = $this->getMockBuilder(\stdClass::class)->addMethods(['getItem'])->getMock();
        $media->method('getItem')->willReturn($item);
        $manager = $this->createMock(DiskQuotaManager::class);
        $manager->expects($this->once())->method('isSiteQuotaExceeded')->with($expectedSite, 100)->willReturn(true);
        $manager->method('getSiteQuota')->willReturn(1000);
        $manager->method('getUsedDiskSpaceBySite')->willReturn(950);
        $errors = $this->getMockBuilder(\stdClass::class)->addMethods(['addError'])->getMock();
        $errors->expects($this->once())->method('addError');
        $services = new ServiceManager();
        $services->setService('DiskQuota\\DiskQuotaManager', $manager);
        $services->setService('Omeka\\Connection', $this->createSiteDatabase());
        $services->setService('Request', new Request());
        $request = $this->getMockBuilder(\stdClass::class)->addMethods(['getOperation', 'getContent'])->getMock();
        $request->method('getOperation')->willReturn('create');
        $data = ['o:size' => 100];
        if ($requestedId !== null) {
            $data['o:item'] = ['o:id' => $requestedId];
        }
        $request->method('getContent')->willReturn($data);
        (new UploadQuotaListener($services))->checkSiteQuotaBeforeUpload(new Event('api.hydrate.pre', null, [
            'request' => $request, 'entity' => $media, 'errorStore' => $errors,
        ]));
    }

    public function nestedUploadCases(): array
    {
        return [
            'new parent without override' => [null, null, 20],
            'existing parent without override' => [1, null, 20],
            'same parent uses pending site assignments' => [1, 1, 20],
            'existing parent replaced by request' => [1, 4, 30],
            'new parent replaced by request' => [null, 4, 30],
        ];
    }

    /** @dataProvider uploadCases */
    public function testUploadChecksOnlyAllAssignedSites(int $itemId, array $checked, ?int $fullSite): void
    {
        $manager = $this->createMock(DiskQuotaManager::class);
        $actual = [];
        $manager->method('isSiteQuotaExceeded')->willReturnCallback(
            function ($siteId, $bytes) use (&$actual, $fullSite) {
                $actual[] = (int) $siteId;
                $this->assertSame(100, $bytes);
                return (int) $siteId === $fullSite;
            }
        );
        $manager->method('getSiteQuota')->willReturn(1000);
        $manager->method('getUsedDiskSpaceBySite')->willReturn(950);
        $errors = $this->getMockBuilder(\stdClass::class)->addMethods(['addError'])->getMock();
        $errors->expects($fullSite === null ? $this->never() : $this->once())->method('addError');
        $services = new ServiceManager();
        $services->setService('Omeka\Connection', $this->createSiteDatabase());
        $services->setService('DiskQuota\DiskQuotaManager', $manager);
        $services->setService('Request', new \stdClass());
        $module = new UploadQuotaListener($services);
        $module->checkSiteQuotaBeforeUpload($this->uploadEvent($itemId, $errors));
        $this->assertSame($checked, $actual);
    }

    public function uploadCases(): array
    {
        return [
            'both sites allowed' => [2, [10, 20], null],
            'second site rejects' => [2, [10, 20], 20],
            'first site rejects' => [2, [10, 20], 10],
            'attached set alone has no site quota' => [5, [], null],
            'site without attached sets' => [4, [30], null],
        ];
    }

    /** @dataProvider httpFileCases */
    public function testHttpUploadStillChecksItemMembership(bool $nested): void
    {
        $file = tempnam(sys_get_temp_dir(), 'diskquota-');
        file_put_contents($file, str_repeat('x', 100));
        try {
            $http = new Request();
            $files = ['file' => ['tmp_name' => $file]];
            $http->setFiles(new Parameters($nested ? ['uploads' => $files] : $files));
            $manager = $this->createMock(DiskQuotaManager::class);
            $manager->expects($this->once())->method('isSiteQuotaExceeded')->with(30, 100)->willReturn(true);
            $manager->method('getSiteQuota')->willReturn(1000);
            $manager->method('getUsedDiskSpaceBySite')->willReturn(950);
            $errors = $this->getMockBuilder(\stdClass::class)->addMethods(['addError'])->getMock();
            $errors->expects($this->once())->method('addError');
            $services = new ServiceManager();
            $services->setService('Omeka\Connection', $this->createSiteDatabase());
            $services->setService('DiskQuota\DiskQuotaManager', $manager);
            $services->setService('Request', $http);
            $module = new UploadQuotaListener($services);
            $module->checkSiteQuotaBeforeUpload($this->uploadEvent(4, $errors));
            $this->assertSame(100, filesize($file));
            $mediaCount = $services->get('Omeka\Connection')->query('SELECT COUNT(*) FROM media')->fetchColumn();
            $this->assertSame(7, (int) $mediaCount);
        } finally {
            unlink($file);
        }
    }

    public function httpFileCases(): array
    {
        return [[false], [true]];
    }

    /** @dataProvider ignoredUploadCases */
    public function testUploadsWithoutNewSizedSiteMediaAreIgnored(string $operation, array $data): void
    {
        $request = $this->getMockBuilder(\stdClass::class)->addMethods(['getOperation', 'getContent'])->getMock();
        $request->method('getOperation')->willReturn($operation);
        $request->method('getContent')->willReturn($data);
        $manager = $this->createMock(DiskQuotaManager::class);
        $manager->expects($this->never())->method('isSiteQuotaExceeded');
        $services = new ServiceManager();
        $services->setService('Request', new Request());
        $services->setService('DiskQuota\\DiskQuotaManager', $manager);
        $module = new UploadQuotaListener($services);
        $module->checkSiteQuotaBeforeUpload(new Event('api.hydrate.pre', null, ['request' => $request]));
    }

    public function ignoredUploadCases(): array
    {
        return [
            ['update', ['o:item' => ['o:id' => 2], 'o:size' => 100]],
            ['create', ['o:item' => ['o:id' => 2]]],
            ['create', ['o:size' => 100]],
        ];
    }

    /** @dataProvider siteQuotaBoundaries */
    public function testRealSiteUsageEnforcesQuotaBoundaries(int $quota, int $bytes, bool $rejected): void
    {
        $db = $this->createSiteDatabase();
        $db->exec('UPDATE media SET size = size * 1024 * 1024');
        $settings = $this->getMockBuilder(\stdClass::class)->addMethods(['get', 'setTargetId'])->getMock();
        $settings->method('get')->willReturn($quota);
        $settings->expects($this->atLeastOnce())->method('setTargetId')->with(10);
        $services = new ServiceManager();
        $services->setService('Omeka\\Connection', $db);
        $services->setService('Omeka\\Settings', new \Omeka\Settings());
        $services->setService('Omeka\\Settings\\Site', $settings);
        $services->setService('DiskQuota\\DiskQuotaManager', new DiskQuotaManager($services));
        $services->setService('Request', new Request());
        $errors = $this->getMockBuilder(\stdClass::class)->addMethods(['addError'])->getMock();
        $errors->expects($rejected ? $this->once() : $this->never())->method('addError');
        $request = $this->getMockBuilder(\stdClass::class)->addMethods(['getOperation', 'getContent'])->getMock();
        $request->method('getOperation')->willReturn('create');
        $request->method('getContent')->willReturn(['o:item' => ['o:id' => 1], 'o:size' => $bytes]);
        $module = new UploadQuotaListener($services);
        $module->checkSiteQuotaBeforeUpload(new Event('api.hydrate.pre', null, [
            'request' => $request, 'errorStore' => $errors,
        ]));
        $this->assertSame(7, (int) $db->query('SELECT COUNT(*) FROM media')->fetchColumn());
    }

    public function siteQuotaBoundaries(): array
    {
        return [
            'below quota' => [501, 1024 * 1024 - 1, false],
            'exact quota' => [501, 1024 * 1024, false],
            'one byte over quota' => [501, 1024 * 1024 + 1, true],
            'unlimited' => [0, 2000 * 1024 * 1024, false],
        ];
    }

    private function uploadEvent(int $itemId, $errors): Event
    {
        $request = $this->getMockBuilder(\stdClass::class)->addMethods(['getOperation', 'getContent'])->getMock();
        $request->method('getOperation')->willReturn('create');
        $request->method('getContent')->willReturn(['o:item' => ['o:id' => $itemId], 'data' => ['size' => 100]]);
        return new Event('api.hydrate.pre', null, ['request' => $request, 'errorStore' => $errors]);
    }
}

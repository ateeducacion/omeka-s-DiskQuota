<?php
declare(strict_types=1);

namespace DiskQuotaTest\Module;

use DiskQuota\Module;
use DiskQuota\Service\DiskQuotaManager;
use DiskQuotaTest\SiteFixture;
use Laminas\EventManager\Event;
use Laminas\Http\Request;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Stdlib\Parameters;
use PHPUnit\Framework\TestCase;

class SiteMembershipTest extends TestCase
{
    use SiteFixture;

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
        $module = new Module();
        $module->setServiceLocator($services);
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
            $module = new Module();
            $module->setServiceLocator($services);
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
        $module = new Module();
        $module->setServiceLocator($services);
        $module->checkSiteQuotaBeforeUpload(new Event('api.create.pre', null, ['request' => $request]));
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
        $module = new Module();
        $module->setServiceLocator($services);
        $module->checkSiteQuotaBeforeUpload(new Event('api.create.pre', null, [
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
        return new Event('api.create.pre', null, ['request' => $request, 'errorStore' => $errors]);
    }
}

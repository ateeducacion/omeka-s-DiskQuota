<?php
declare(strict_types=1);

namespace DiskQuotaTest\Service;

use DiskQuota\Service\DiskQuotaManager;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;

class DiskQuotaManagerSiteTest extends TestCase
{
    public function testGetUsedDiskSpaceBySiteAggregatesBothQueries(): void
    {
        // First statement returns size from direct items (30 MB)
        $stmt1 = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['execute', 'fetchColumn', 'bindValue'])
            ->getMock();
        $stmt1->method('execute')->willReturn(true);
        $stmt1->method('fetchColumn')->willReturn(30 * 1024 * 1024);
        $stmt1->method('bindValue')->willReturnSelf();

        // Second statement returns size from item sets (70 MB)
        $stmt2 = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['execute', 'fetchColumn', 'bindValue'])
            ->getMock();
        $stmt2->method('execute')->willReturn(true);
        $stmt2->method('fetchColumn')->willReturn(70 * 1024 * 1024);
        $stmt2->method('bindValue')->willReturnSelf();

        // Connection returns stmt1, then stmt2 on subsequent prepare calls
        $connection = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['prepare'])
            ->getMock();
        $connection->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnOnConsecutiveCalls($stmt1, $stmt2);

        // Minimal settings mocks (not used by this method)
        $services = $this->createMock(ServiceManager::class);
        $services->method('get')
            ->will($this->returnValueMap([
                ['Omeka\\Connection', $connection],
            ]));

        $mgr = new DiskQuotaManager($services);
        $total = $mgr->getUsedDiskSpaceBySite(123);
        $this->assertSame(100 * 1024 * 1024, $total, 'Should sum both site usage queries');
    }

    public function testGetSiteQuotaUsesSiteSettingOrDefaultAndConvertsToBytes(): void
    {
        $globalSettings = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['get'])
            ->getMock();
        $globalSettings->method('get')->willReturn(1000); // default 1000 MB if site setting absent

        $siteSettings = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['get', 'setTargetId'])
            ->getMock();
        $siteSettings->method('setTargetId')->willReturnSelf();
        $siteSettings->method('get')->willReturn(200); // site-specific 200 MB

        $services = $this->createMock(ServiceManager::class);
        $services->method('get')
            ->will($this->returnValueMap([
                ['Omeka\\Settings', $globalSettings],
                ['Omeka\\Settings\\Site', $siteSettings],
            ]));

        $mgr = new DiskQuotaManager($services);
        $bytes = $mgr->getSiteQuota(77);
        $this->assertSame(200 * 1024 * 1024, $bytes, 'Site quota should be returned in bytes');
    }

    public function testGetSiteQuotaUnlimitedReturnsZeroBytes(): void
    {
        $globalSettings = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['get'])
            ->getMock();
        $globalSettings->method('get')->willReturn(0);

        $siteSettings = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['get', 'setTargetId'])
            ->getMock();
        $siteSettings->method('setTargetId')->willReturnSelf();
        $siteSettings->method('get')->willReturn(0); // unlimited

        $services = $this->createMock(ServiceManager::class);
        $services->method('get')
            ->will($this->returnValueMap([
                ['Omeka\\Settings', $globalSettings],
                ['Omeka\\Settings\\Site', $siteSettings],
            ]));

        $mgr = new DiskQuotaManager($services);
        $this->assertSame(0, $mgr->getSiteQuota(1));
    }

    public function testIsSiteQuotaExceededRespectsUnlimitedAndThreshold(): void
    {
        // Site quota 100 MB
        $mgr = $this->getMockBuilder(DiskQuotaManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSiteQuota', 'getUsedDiskSpaceBySite'])
            ->getMock();
        $mgr->method('getSiteQuota')->willReturn(100 * 1024 * 1024);
        $mgr->method('getUsedDiskSpaceBySite')->willReturn(90 * 1024 * 1024);

        // 5 MB additional should NOT exceed, 20 MB should exceed
        $this->assertFalse($mgr->isSiteQuotaExceeded(7, 5 * 1024 * 1024));
        $this->assertTrue($mgr->isSiteQuotaExceeded(7, 20 * 1024 * 1024));

        // Unlimited quota (0) should never exceed
        $mgrUnlimited = $this->getMockBuilder(DiskQuotaManager::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSiteQuota', 'getUsedDiskSpaceBySite'])
            ->getMock();
        $mgrUnlimited->method('getSiteQuota')->willReturn(0);
        $mgrUnlimited->method('getUsedDiskSpaceBySite')->willReturn(999 * 1024 * 1024);
        $this->assertFalse($mgrUnlimited->isSiteQuotaExceeded(7, 999 * 1024 * 1024));
    }
}

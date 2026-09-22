<?php
declare(strict_types=1);

namespace DiskQuotaTest\Service;

use DiskQuota\Service\DiskQuotaManager;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;

class DiskQuotaManagerSiteTest extends TestCase
{
    use \DiskQuotaTest\SiteFixture;

    public function testSiteUsageCountsAssignedItemsOnceRegardlessOfAttachedSets(): void
    {
        $services = new ServiceManager();
        $services->setService('Omeka\\Connection', $this->createSiteDatabase());
        $manager = new DiskQuotaManager($services);

        $this->assertSame(500, $manager->getUsedDiskSpaceBySite(10));
        $this->assertSame(700, $manager->getUsedDiskSpaceBySite(20));
        $this->assertSame(500, $manager->getUsedDiskSpaceBySite(30));
        $this->assertSame(0, $manager->getUsedDiskSpaceBySite(40));
        $this->assertSame(0, $manager->getUsedDiskSpaceBySite(99));
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
        $this->assertFalse($mgr->isSiteQuotaExceeded(7, 10 * 1024 * 1024));
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

<?php
declare(strict_types=1);

namespace DiskQuotaTest\Module;

use DiskQuota\Listener\SiteAssignmentListener;
use DiskQuota\Listener\UploadQuotaListener;
use DiskQuota\Service\DiskQuotaManager;
use DiskQuotaTest\SiteFixture;
use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Api\Exception\ValidationException;
use Omeka\Stdlib\ErrorStore;
use PHPUnit\Framework\TestCase;

class SiteMembershipTest extends TestCase
{
    use SiteFixture;

    /** @dataProvider uploadCases */
    public function testIngestChecksAllAssignedSites(array $siteIds, ?int $fullSite): void
    {
        $actual = [];
        $manager = $this->createMock(DiskQuotaManager::class);
        $manager->method('isSiteQuotaExceeded')->willReturnCallback(
            function ($siteId, $bytes) use (&$actual, $fullSite) {
                $actual[] = $siteId;
                $this->assertSame(100, $bytes);
                return $siteId === $fullSite;
            }
        );
        $manager->method('getSiteQuota')->willReturn(1000);
        $manager->method('getUsedDiskSpaceBySite')->willReturn(950);
        $services = new ServiceManager();
        $services->setService('DiskQuota\DiskQuotaManager', $manager);
        $file = $this->double(['getSize' => 100]);
        $item = $this->double(['getSites' => array_map(fn ($id) => $this->double(['getId' => $id]), $siteIds)]);
        // A persisted id keeps the accepted file out of the cleanup.
        $media = $this->double(['getId' => 1, 'getOwner' => null, 'getItem' => $item]);
        $errors = new ErrorStore();
        if ($fullSite !== null) {
            $this->expectException(ValidationException::class);
        }
        $listener = new UploadQuotaListener($services);
        try {
            $listener->checkIngestFileQuota(new Event('media.ingest_file.pre', $media, [
                'request' => $this->double(['getOperation' => 'create']),
                'tempFile' => $file, 'storeOriginal' => true, 'errorStore' => $errors,
            ]));
        } finally {
            $this->assertSame($siteIds, $actual);
            // Mocks are reset after the test; clear the tracked file before shutdown sees it.
            $listener->cleanupUnpersistedFiles();
        }
    }

    public function uploadCases(): array
    {
        return [
            'both sites allowed' => [[10, 20], null],
            'second site rejects' => [[10, 20], 20],
            'first site rejects' => [[10, 20], 10],
            'no site' => [[], null],
        ];
    }

    /** @dataProvider assignmentCases */
    public function testAddingAnExistingItemToASiteChecksItsStoredOriginals(
        array $siteIds,
        bool $dirty,
        array $expected,
        bool $rejected
    ): void {
        $actual = [];
        $manager = $this->createMock(DiskQuotaManager::class);
        $manager->method('isSiteQuotaExceeded')->willReturnCallback(
            function ($siteId, $bytes) use (&$actual) {
                $actual[] = [$siteId, $bytes];
                return $siteId === 30;
            }
        );
        $services = new ServiceManager();
        $services->setService('Omeka\Connection', $this->createSiteDatabase());
        $services->setService('DiskQuota\DiskQuotaManager', $manager);
        // Item 2 is stored in sites 10 and 20 with 300 bytes of originals.
        $sites = new class (array_map(fn ($id) => $this->double(['getId' => $id]), $siteIds), $dirty) extends
            \ArrayIterator {
            private $dirty;
            public function __construct(array $sites, bool $dirty)
            {
                parent::__construct($sites);
                $this->dirty = $dirty;
            }
            public function isDirty(): bool
            {
                return $this->dirty;
            }
        };
        $item = $this->double(['getId' => 2, 'getSites' => $sites, 'getMedia' => [
            $this->double(['getId' => 21, 'hasOriginal' => true, 'getSize' => 300]),
            $this->double(['getId' => 22, 'hasOriginal' => false, 'getSize' => 900]),
            // Ingested in this same request: the added site gains it together with media 21.
            $this->double(['getId' => null, 'hasOriginal' => true, 'getSize' => 5000]),
        ]]);
        $errors = new ErrorStore();
        (new SiteAssignmentListener($services))->check(new Event('api.hydrate.post', null, [
            'entity' => $item, 'errorStore' => $errors,
        ]));
        $this->assertSame($expected, $actual);
        $this->assertSame($rejected, $errors->hasErrors());
    }

    public function assignmentCases(): array
    {
        return [
            'unchanged sites are not queried' => [[10, 20], false, [], false],
            'kept sites are not rechecked' => [[10, 20], true, [], false],
            'removed site is not checked' => [[10], true, [], false],
            'new site counts kept and new originals' => [[10, 20, 40], true, [[40, 5300]], false],
            'new full site rejects' => [[10, 30], true, [[30, 5300]], true],
        ];
    }

    public function testNewItemsAndFailedHydrationAreLeftToIngestChecks(): void
    {
        $manager = $this->createMock(DiskQuotaManager::class);
        $manager->expects($this->never())->method('isSiteQuotaExceeded');
        $services = new ServiceManager();
        $services->setService('DiskQuota\DiskQuotaManager', $manager);
        $listener = new SiteAssignmentListener($services);
        $listener->check(new Event('api.hydrate.post', null, [
            'entity' => $this->double(['getId' => null, 'getSites' => []]), 'errorStore' => new ErrorStore(),
        ]));
        $errors = new ErrorStore();
        $errors->addError('o:title', 'Invalid');
        $listener->check(new Event('api.hydrate.post', null, [
            'entity' => $this->double(['getId' => 2, 'getSites' => []]), 'errorStore' => $errors,
        ]));
    }

    private function double(array $values)
    {
        $double = $this->getMockBuilder(\stdClass::class)->addMethods(array_keys($values))->getMock();
        foreach ($values as $method => $value) {
            $double->method($method)->willReturn($value);
        }
        return $double;
    }
}

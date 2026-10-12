<?php
declare(strict_types=1);

namespace DiskQuotaTest\Module;

use DiskQuota\Listener\SiteAssignmentListener;
use DiskQuota\Service\PendingUploads;
use DiskQuotaTest\SiteFixture;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;
use Laminas\EventManager\SharedEventManager;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Stdlib\ErrorStore;
use PHPUnit\Framework\TestCase;

class SiteAssignmentTest extends TestCase
{
    use SiteFixture;

    private $db;
    private $services;
    private $events;
    private $quota = 600;

    protected function setUp(): void
    {
        $this->db = $this->createSiteDatabase();
        $this->db->exec('UPDATE media SET size = size * 1048576');
        $connection = new class($this->db) {
            private $db;
            public function __construct($db)
            {
                $this->db = $db;
            }
            public function prepare($sql)
            {
                return $this->db->prepare($sql);
            }
            public function fetchOne($sql, $params)
            {
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchColumn();
            }
            public function fetchAll($sql, $params)
            {
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            }
        };
        $this->services = new ServiceManager((new \DiskQuota\Module())->getServiceConfig());
        $this->services->setService('Omeka\Connection', $connection);
        $this->services->setService('Omeka\Settings', new \Omeka\Settings());
        $settings = $this->getMockBuilder(\stdClass::class)->addMethods(['get', 'setTargetId'])->getMock();
        $settings->method('get')->willReturnCallback(fn () => $this->quota);
        $this->services->setService('Omeka\Settings\Site', $settings);
        $entityManager = $this->getMockBuilder(\stdClass::class)->addMethods(['refresh'])->getMock();
        $this->services->setService('Omeka\EntityManager', $entityManager);
        $module = new \DiskQuota\Module();
        $module->setServiceLocator($this->services);
        $shared = new SharedEventManager();
        $module->attachListeners($shared);
        $this->events = new EventManager($shared, ['Omeka\\Api\\Adapter\\ItemAdapter']);
    }

    public function testRegisteredListenerRejectsExistingOriginalsAndIgnoresThumbnails(): void
    {
        $this->assertTrue($this->check($this->item(1, [30]))->hasErrors());
        $this->quota = 700;
        $this->assertFalse($this->check($this->item(1, [30]))->hasErrors());
    }

    public function testAppendingASiteDoesNotChargeTheOriginalSiteAgain(): void
    {
        $this->assertFalse($this->check($this->item(1, [10, 40]))->hasErrors());
        $this->assertTrue($this->check($this->item(1, [10, 30]))->hasErrors());
    }

    public function testUnchangedOverQuotaMembershipAndRemovalRemainAllowed(): void
    {
        $this->quota = 1;
        $this->assertFalse($this->check($this->item(1, [10]))->hasErrors());
        $this->assertFalse($this->check($this->item(1, []))->hasErrors());
    }

    public function testRemovedOriginalIsSubtractedFromTheProjectedDestinationUsage(): void
    {
        $item = $this->item(1, [30], [11]);
        $this->assertFalse($this->check($item)->hasErrors());
    }

    public function testPendingSiteAssignmentsAreAccumulatedAndAnAbortedBatchRefreshesEarlierItems(): void
    {
        $this->assertFalse($this->check($this->item(1, [40]))->hasErrors());
        $this->assertFalse($this->check($this->item(3, [40]))->hasErrors());
        $this->services->get('Omeka\EntityManager')->expects($this->exactly(2))->method('refresh');
        $this->assertTrue($this->check($this->item(4, [40]))->hasErrors());
        [, $bytes] = $this->services->get(PendingUploads::class)->getUploadBytes(null, null, 0, [40]);
        $this->assertSame([40 => 0], $bytes);
    }

    public function testContinueOnErrorKeepsEarlierReservationsAndUploadsSeeThem(): void
    {
        $pending = $this->services->get(PendingUploads::class);
        $pending->setContinueBatch(true);
        $this->assertFalse($this->check($this->item(3, [40]))->hasErrors());
        $this->assertTrue($this->check($this->item(4, [40]))->hasErrors());
        [, $bytes] = $pending->getUploadBytes(null, null, 50, [40]);
        $this->assertSame([40 => 400 * 1048576 + 50], $bytes);
        $pending->clearSiteChanges();
        [, $bytes] = $pending->getUploadBytes(null, null, 50, [40]);
        $this->assertSame([40 => 50], $bytes);
    }

    public function testQueryFailureCannotApproveASiteAssignment(): void
    {
        $this->db->exec('DROP TABLE item_site');
        $this->expectException(\PDOException::class);
        $this->check($this->item(1, [30]));
    }

    public function testNewItemsWithNoOriginalsDoNotConsumeQuota(): void
    {
        $this->assertFalse($this->check($this->item(null, [30]))->hasErrors());
    }

    public function testAnAlreadyFailedHydrationSkipsSiteQueries(): void
    {
        $errors = new ErrorStore();
        $errors->addError('o:media', 'Failed child');
        $this->db->exec('DROP TABLE item_site');
        $this->services->get(SiteAssignmentListener::class)->check(new Event('api.hydrate.post', null, [
            'errorStore' => $errors,
        ]));
        $this->assertTrue($errors->hasErrors());
    }

    private function item(?int $id, array $sites, ?array $retain = null)
    {
        $item = $this->getMockBuilder(\stdClass::class)->addMethods(['getId', 'getSites', 'getMedia'])->getMock();
        $item->method('getId')->willReturn($id);
        $item->method('getSites')->willReturn(array_map(function ($id) {
            $site = $this->getMockBuilder(\stdClass::class)->addMethods(['getId'])->getMock();
            $site->method('getId')->willReturn($id);
            return $site;
        }, $sites));
        $stmt = $this->db->prepare('SELECT * FROM media WHERE item_id = ?');
        $stmt->execute([$id]);
        $media = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if ($retain !== null && !in_array($row['id'], $retain)) {
                continue;
            }
            $file = $this->getMockBuilder(\stdClass::class)->addMethods(['getId', 'hasOriginal', 'getSize'])->getMock();
            $file->method('getId')->willReturn((int) $row['id']);
            $file->method('hasOriginal')->willReturn((bool) $row['has_original']);
            $file->method('getSize')->willReturn((int) $row['size']);
            $media[] = $file;
        }
        $item->method('getMedia')->willReturn($media);
        return $item;
    }

    private function check($item): ErrorStore
    {
        $errors = new ErrorStore();
        $this->events->triggerEvent(new Event('api.hydrate.post', null, [
            'entity' => $item, 'errorStore' => $errors,
        ]));
        return $errors;
    }
}

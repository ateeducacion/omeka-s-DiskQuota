<?php
declare(strict_types=1);

namespace DiskQuotaTest\Module;

use DiskQuota\Listener\SiteResourcesListener;
use DiskQuota\Service\DiskQuotaManager;
use DiskQuotaTest\SiteFixture;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Laminas\Stdlib\Parameters;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;

class SiteResourcesListenerTest extends TestCase
{
    use SiteFixture;

    /** @dataProvider assignmentCases */
    public function testAssignmentChecksTheBytesTheSiteWouldGain(
        string $action,
        array $itemIds,
        ?int $expectedBytes,
        bool $full
    ): void {
        $checked = [];
        $manager = $this->createMock(DiskQuotaManager::class);
        // Site 10 already holds items 1 and 2 (500 bytes of originals).
        $manager->method('getSiteQuota')->with(10)->willReturn(1000);
        $manager->method('getUsedDiskSpaceBySite')->with(10)->willReturn(500);
        $manager->method('isSiteQuotaExceeded')->willReturnCallback(
            function ($siteId, $bytes) use (&$checked, $full) {
                $checked[] = [$siteId, $bytes];
                return $full;
            }
        );
        $services = new ServiceManager();
        $services->setService('Omeka\Connection', $this->createSiteDatabase());
        $services->setService('DiskQuota\DiskQuotaManager', $manager);
        $acl = $this->acl(false);
        $services->setService('Omeka\Acl', $acl);
        $api = $this->getMockBuilder(\stdClass::class)->addMethods(['search'])->getMock();
        $api->expects($this->once())->method('search')
            ->with('items', ['resource_class_id' => ['5']], ['returnScalar' => 'id'])
            ->willReturnCallback(function () use ($acl, $itemIds) {
                // The job assigns private items too, so the pool must be resolved with view-all.
                $this->assertTrue($acl->granted);
                return $this->double(['getContent' => $itemIds]);
            });
        $services->setService('Omeka\ApiManager', $api);
        $response = new Response();
        $messenger = $this->double(['addError' => null]);
        $messenger->expects($full ? $this->once() : $this->never())->method('addError');
        $controller = $this->double([
            'currentSite' => $this->double(['id' => 10]),
            'messenger' => $messenger,
            'redirect' => $this->double(['refresh' => $response]),
        ]);
        $result = (new SiteResourcesListener($services))->checkItemAssignment(
            $this->event($controller, 'resources', $this->post($action))
        );
        $this->assertSame($expectedBytes === null ? [] : [[10, $expectedBytes]], $checked);
        $this->assertSame($full ? $response : null, $result);
        $this->assertFalse($acl->granted, 'view-all must not outlive the search');
    }

    public function assignmentCases(): array
    {
        return [
            'add counts only items not yet in the site' => ['add', [1, 3, 4], 900, false],
            'add to a full site is rejected' => ['add', [1, 3, 4], 900, true],
            'add of items already in the site gains nothing' => ['add', [1, 2], null, false],
            'replace counts the pool minus current usage' => ['replace', [3, 4, 5], 1000, true],
            'replace that shrinks the site is allowed' => ['replace', [3], null, false],
            'empty pool' => ['add', [], null, false],
        ];
    }

    public function testUnlimitedSitesSkipTheSearch(): void
    {
        $manager = $this->createMock(DiskQuotaManager::class);
        $manager->method('getSiteQuota')->with(10)->willReturn(0);
        $manager->expects($this->never())->method('isSiteQuotaExceeded');
        $api = $this->getMockBuilder(\stdClass::class)->addMethods(['search'])->getMock();
        $api->expects($this->never())->method('search');
        $services = new ServiceManager();
        $services->setService('DiskQuota\DiskQuotaManager', $manager);
        $services->setService('Omeka\ApiManager', $api);
        $controller = $this->double(['currentSite' => $this->double(['id' => 10])]);
        $this->assertNull((new SiteResourcesListener($services))->checkItemAssignment(
            $this->event($controller, 'resources', $this->post('replace'))
        ));
    }

    public function testAnExistingViewAllGrantIsLeftInPlace(): void
    {
        $manager = $this->createMock(DiskQuotaManager::class);
        $manager->method('getSiteQuota')->willReturn(1000);
        $acl = $this->acl(true);
        $services = new ServiceManager();
        $services->setService('Omeka\Connection', $this->createSiteDatabase());
        $services->setService('DiskQuota\DiskQuotaManager', $manager);
        $services->setService('Omeka\Acl', $acl);
        $services->setService('Omeka\ApiManager', $this->double([
            'search' => $this->double(['getContent' => []]),
        ]));
        $controller = $this->double(['currentSite' => $this->double(['id' => 10])]);
        (new SiteResourcesListener($services))->checkItemAssignment(
            $this->event($controller, 'resources', $this->post('add'))
        );
        $this->assertTrue($acl->granted);
        $this->assertSame([], $acl->changes);
    }

    /** @dataProvider ignoredCases */
    public function testOtherRequestsAreIgnored(string $routeAction, Request $request): void
    {
        $services = new ServiceManager();
        $result = (new SiteResourcesListener($services))->checkItemAssignment(
            $this->event($this->double([]), $routeAction, $request)
        );
        $this->assertNull($result);
    }

    public function ignoredCases(): array
    {
        return [
            'other action' => ['edit', $this->post('add')],
            'get request' => ['resources', new Request()],
            'remove' => ['resources', $this->post('remove')],
            'no action' => ['resources', $this->post('no_action')],
        ];
    }

    private function acl(bool $granted)
    {
        return new class ($granted) {
            public $granted;
            public $changes = [];
            public function __construct(bool $granted)
            {
                $this->granted = $granted;
            }
            public function isAllowed($role, $resource, $privilege): bool
            {
                return $role === null && $resource === 'Omeka\Entity\Resource' && $privilege === 'view-all'
                    && $this->granted;
            }
            public function allow($role, $resource, $privilege): void
            {
                $this->changes[] = ['allow', $role, $resource, $privilege];
                $this->granted = true;
            }
            public function removeAllow($role, $resource, $privilege): void
            {
                $this->changes[] = ['removeAllow', $role, $resource, $privilege];
                $this->granted = false;
            }
        };
    }

    private function post(string $action): Request
    {
        $request = new Request();
        $request->setMethod(Request::METHOD_POST);
        $request->setPost(new Parameters([
            'item_assignment_action' => $action,
            'item_pool' => 'resource_class_id%5B0%5D=5',
        ]));
        return $request;
    }

    private function event($controller, string $routeAction, Request $request)
    {
        return $this->double([
            'getTarget' => $controller,
            'getRequest' => $request,
            'getRouteMatch' => $this->double(['getParam' => $routeAction]),
        ]);
    }

    private function double(array $values)
    {
        $double = $this->getMockBuilder(\stdClass::class)->addMethods(array_keys($values) ?: ['noop'])->getMock();
        foreach ($values as $method => $value) {
            $double->method($method)->willReturn($value);
        }
        return $double;
    }
}

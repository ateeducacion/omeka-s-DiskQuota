<?php
declare(strict_types=1);

namespace DiskQuotaTest\Module;

use DiskQuota\Form\ConfigForm;
use DiskQuota\Module;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;

class QuotaHandlersTest extends TestCase
{
    private function makeServiceManager(array $map): ServiceManager
    {
        $sm = $this->createMock(ServiceManager::class);
        $sm->method('get')->will($this->returnValueMap($map));
        return $sm;
    }

    public function testCheckUserQuotaBeforeUploadAddsErrorWhenExceeded(): void
    {
        $module = new Module();

        $user = $this->getMockBuilder(\stdClass::class)->addMethods(['getId'])->getMock();
        $user->method('getId')->willReturn(1);
        $auth = $this->getMockBuilder(\stdClass::class)->addMethods(['getIdentity'])->getMock();
        $auth->method('getIdentity')->willReturn($user);

        $dqm = $this->getMockBuilder('DiskQuota\\Service\\DiskQuotaManager')->disableOriginalConstructor()->getMock();
        $dqm->method('isQuotaExceeded')->willReturn(true);
        $dqm->method('getUserQuota')->willReturn(100 * 1024 * 1024);
        $dqm->method('getUsedDiskSpaceByUser')->willReturn(95 * 1024 * 1024);

        $httpReq = new \stdClass();

        $apiReq = $this->getMockBuilder(\stdClass::class)->addMethods(['getOperation', 'getContent'])->getMock();
        $apiReq->method('getOperation')->willReturn('create');
        $apiReq->method('getContent')->willReturn(['data' => ['size' => 10 * 1024 * 1024]]);

        $errorStore = $this->getMockBuilder(\stdClass::class)->addMethods(['addError'])->getMock();
        $errorStore->expects($this->once())->method('addError');

        $event = $this->getMockBuilder(\stdClass::class)->addMethods(['getParam'])->getMock();
        $event->method('getParam')->willReturnMap([
            ['request', $apiReq],
            ['errorStore', $errorStore],
        ]);

        $services = $this->makeServiceManager([
            ['Omeka\\AuthenticationService', $auth],
            ['DiskQuota\\DiskQuotaManager', $dqm],
            ['Request', $httpReq],
        ]);
        $module->setServiceLocator($services);
        $module->checkUserQuotaBeforeUpload($event);
        $this->assertTrue(true);
    }

    public function testCheckUserQuotaBeforeUploadNoErrorWhenWithinQuota(): void
    {
        $module = new Module();

        $user = $this->getMockBuilder(\stdClass::class)->addMethods(['getId'])->getMock();
        $user->method('getId')->willReturn(1);
        $auth = $this->getMockBuilder(\stdClass::class)->addMethods(['getIdentity'])->getMock();
        $auth->method('getIdentity')->willReturn($user);

        $dqm = $this->getMockBuilder('DiskQuota\\Service\\DiskQuotaManager')->disableOriginalConstructor()->getMock();
        $dqm->method('isQuotaExceeded')->willReturn(false);

        $httpReq = new \stdClass();

        $apiReq = $this->getMockBuilder(\stdClass::class)->addMethods(['getOperation', 'getContent'])->getMock();
        $apiReq->method('getOperation')->willReturn('create');
        $apiReq->method('getContent')->willReturn(['data' => ['size' => 1 * 1024 * 1024]]);

        $errorStore = $this->getMockBuilder(\stdClass::class)->addMethods(['addError'])->getMock();
        $errorStore->expects($this->never())->method('addError');

        $event = $this->getMockBuilder(\stdClass::class)->addMethods(['getParam'])->getMock();
        $event->method('getParam')->willReturnMap([
            ['request', $apiReq],
            ['errorStore', $errorStore],
        ]);

        $services = $this->makeServiceManager([
            ['Omeka\\AuthenticationService', $auth],
            ['DiskQuota\\DiskQuotaManager', $dqm],
            ['Request', $httpReq],
        ]);
        $module->setServiceLocator($services);
        $module->checkUserQuotaBeforeUpload($event);
        $this->assertTrue(true);
    }

    public function testHandleConfigFormSavesSettingsOnValid(): void
    {
        $module = new Module();

        $settings = $this->getMockBuilder(\stdClass::class)->addMethods(['set'])->getMock();
        $settings->expects($this->exactly(4))->method('set')
            ->withConsecutive(
                ['diskquota_default_site_quota', 1000],
                ['diskquota_default_user_quota', 500],
                ['diskquota_default_global_quota', 10000],
                ['diskquota_warning_threshold', 15]
            );

        $fem = $this->getMockBuilder(\stdClass::class)->addMethods(['get'])->getMock();
        $fem->method('get')->willReturnCallback(function ($class) {
            $form = new ConfigForm();
            $form->init();
            return $form;
        });

        $services = $this->makeServiceManager([
            ['Omeka\\Settings', $settings],
            ['FormElementManager', $fem],
        ]);
        $module->setServiceLocator($services);

        $request = $this->getMockBuilder(\stdClass::class)->addMethods(['getPost'])->getMock();
        $request->method('getPost')->willReturn([
            'diskquota_default_global_quota' => 10000,
            'diskquota_default_site_quota' => 1000,
            'diskquota_default_user_quota' => 500,
            'diskquota_warning_threshold' => 15,
        ]);
        $messenger = $this->getMockBuilder(\stdClass::class)->addMethods(['addSuccess'])->getMock();
        $messenger->expects($this->once())->method('addSuccess');

        $controller = new class {
            public $req;
            public $msg;
            public function getRequest()
            {
                return $this->req;
            }
            public function messenger()
            {
                return $this->msg;
            }
        };
        $controller->req = $request;
        $controller->msg = $messenger;

        $this->assertTrue($module->handleConfigForm($controller));
    }

    public function testHandleConfigFormAddsErrorsOnInvalid(): void
    {
        $module = new Module();

        $settings = $this->getMockBuilder(\stdClass::class)->addMethods(['set'])->getMock();

        $fem = $this->getMockBuilder(\stdClass::class)->addMethods(['get'])->getMock();
        $fem->method('get')->willReturnCallback(function ($class) {
            $form = new ConfigForm();
            $form->init();
            return $form;
        });

        $services = $this->makeServiceManager([
            ['Omeka\\Settings', $settings],
            ['FormElementManager', $fem],
        ]);
        $module->setServiceLocator($services);

        $request = $this->getMockBuilder(\stdClass::class)->addMethods(['getPost'])->getMock();
        $request->method('getPost')->willReturn([
            'diskquota_default_global_quota' => -10,
            'diskquota_default_site_quota' => 1000,
            'diskquota_default_user_quota' => 500,
            'diskquota_warning_threshold' => 15,
        ]);
        $messenger = $this->getMockBuilder(\stdClass::class)->addMethods(['addErrors'])->getMock();
        $messenger->expects($this->once())->method('addErrors');

        $controller = new class {
            public $req;
            public $msg;
            public function getRequest()
            {
                return $this->req;
            }
            public function messenger()
            {
                return $this->msg;
            }
        };
        $controller->req = $request;
        $controller->msg = $messenger;

        $this->assertFalse($module->handleConfigForm($controller));
    }

    public function testCheckDiskQuotaBeforeUploadAddsErrorWhenExceeded(): void
    {
        $module = new Module();

        $tmp = tempnam(sys_get_temp_dir(), 'dq');
        file_put_contents($tmp, str_repeat('A', 2 * 1024 * 1024));

        $api = $this->getMockBuilder(\stdClass::class)->addMethods(['read', 'search'])->getMock();

        $item = $this->getMockBuilder(\stdClass::class)->addMethods(['itemSets', 'id'])->getMock();
        $item->method('id')->willReturn(10);
        $itemSet = $this->getMockBuilder(\stdClass::class)->addMethods(['id'])->getMock();
        $itemSet->method('id')->willReturn(20);
        $item->method('itemSets')->willReturn([$itemSet]);
        $readResult = $this->getMockBuilder(\stdClass::class)->addMethods(['getContent'])->getMock();
        $readResult->method('getContent')->willReturn($item);
        $api->method('read')->willReturn($readResult);

        $site = $this->getMockBuilder(\stdClass::class)->addMethods(['id'])->getMock();
        $site->method('id')->willReturn(99);
        $searchResult = $this->getMockBuilder(\stdClass::class)->addMethods(['getContent'])->getMock();
        $searchResult->method('getContent')->willReturn([$site]);
        $api->method('search')->willReturn($searchResult);

        $stmt = $this->getMockBuilder(\stdClass::class)->addMethods(['bindValue', 'execute', 'fetch'])->getMock();
        $stmt->method('bindValue')->willReturnSelf();
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'quota_size' => (int)(1.5 * 1024 * 1024),
            'current_usage' => (int)(0.4 * 1024 * 1024),
        ]);
        $conn = $this->getMockBuilder(\stdClass::class)->addMethods(['prepare'])->getMock();
        $conn->method('prepare')->willReturn($stmt);

        $apiReq = $this->getMockBuilder(\stdClass::class)->addMethods(['getFiles', 'getValue'])->getMock();
        $apiReq->method('getFiles')->willReturn(['file' => ['tmp_name' => $tmp]]);
        $oItem = $this->getMockBuilder(\stdClass::class)->addMethods(['getId'])->getMock();
        $oItem->method('getId')->willReturn(10);
        $apiReq->method('getValue')->willReturnMap([
            ['o:item', $oItem],
        ]);

        $errorStore = $this->getMockBuilder(\stdClass::class)->addMethods(['addError'])->getMock();
        $errorStore->expects($this->once())->method('addError');

        $event = $this->getMockBuilder(\stdClass::class)->addMethods(['getParam'])->getMock();
        $event->method('getParam')->willReturnMap([
            ['request', $apiReq],
            ['errorStore', $errorStore],
        ]);

        $services = $this->makeServiceManager([
            ['Omeka\\ApiManager', $api],
            ['Omeka\\Connection', $conn],
        ]);
        $module->setServiceLocator($services);
        $module->checkDiskQuotaBeforeUpload($event);

        @unlink($tmp);
        $this->assertTrue(true);
    }
}

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
        $module = new \DiskQuota\Listener\UploadQuotaListener($services);
        $module->checkUserQuotaBeforeUpload($event);
        $this->assertTrue(true);
    }

    public function testCheckUserQuotaBeforeUploadNoErrorWhenWithinQuota(): void
    {
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
        $module = new \DiskQuota\Listener\UploadQuotaListener($services);
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

        $controller = new class extends \Laminas\Mvc\Controller\AbstractController {
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
            public function onDispatch(\Laminas\Mvc\MvcEvent $e)
            {
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

        $controller = new class extends \Laminas\Mvc\Controller\AbstractController {
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
            public function onDispatch(\Laminas\Mvc\MvcEvent $e)
            {
            }
        };
        $controller->req = $request;
        $controller->msg = $messenger;

        $this->assertFalse($module->handleConfigForm($controller));
    }
}

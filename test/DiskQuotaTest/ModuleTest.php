<?php
declare(strict_types=1);

namespace DiskQuotaTest;

use PHPUnit\Framework\TestCase;
use Laminas\EventManager\SharedEventManager;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Settings;
use Omeka\Settings\User as UserSettings;
use Omeka\Api\Manager as ApiManager;
use Omeka\Entity\User;

class ModuleTest extends TestCase
{
    protected $module;
    protected $serviceLocatorMock;
    
    protected function setUp(): void
    {
        // Create a module instance
        $this->module = new \DiskQuota\Module();
        
        // Configure mocks for the service locator
        $this->serviceLocatorMock = $this->createMock(ServiceManager::class);
        
        // Create mocks for services obtained from the service locator
        $settingsMock = $this->createMock(Settings::class);
        $userSettingsMock = $this->createMock(UserSettings::class);
        $apiManagerMock = $this->createMock(ApiManager::class);
        
        // Configure the service locator to return our mocks
        $this->serviceLocatorMock->method('get')
            ->will($this->returnValueMap([
                ['Omeka\Settings', $settingsMock],
                ['Omeka\Settings\User', $userSettingsMock],
                ['Omeka\ApiManager', $apiManagerMock],
                ['FormElementManager', $this->createMock(ServiceManager::class)],
                ['Config', []],
                // Add here other services that your module uses
            ]));
        
        // Inject the service locator into the module
        $this->module->setServiceLocator($this->serviceLocatorMock);
    }
    
    public function testGetConfig(): void
    {
        $config = $this->module->getConfig();
        $this->assertIsArray($config);
        // Continue with specific assertions about the config content
    }
    
    public function testGetServiceConfig(): void
    {
        $serviceConfig = $this->module->getServiceConfig();
        $this->assertIsArray($serviceConfig);
        $this->assertArrayHasKey('factories', $serviceConfig);
        // More specific assertions
    }
    
    public function testAttachListeners(): void
    {
        $services = new ServiceManager($this->module->getServiceConfig());
        $this->module->setServiceLocator($services);
        $events = new SharedEventManager();
        $this->module->attachListeners($events);
        $uploads = $services->get(\DiskQuota\Listener\UploadQuotaListener::class);
        $users = $services->get(\DiskQuota\Listener\UserQuotaListener::class);
        $sites = $services->get(\DiskQuota\Listener\SiteQuotaListener::class);
        $expected = [
            ['Omeka\\Api\\Adapter\\MediaAdapter', 'api.hydrate.pre', $uploads, 'checkUserQuotaBeforeUpload'],
            ['Omeka\\Api\\Adapter\\ItemAdapter', 'api.hydrate.pre', $uploads, 'checkUserQuotaBeforeUpload'],
            ['Omeka\\Api\\Adapter\\MediaAdapter', 'api.create.pre', $uploads, 'checkUserQuotaBeforeUpload'],
            ['Omeka\\Api\\Adapter\\MediaAdapter', 'api.create.pre', $uploads, 'checkSiteQuotaBeforeUpload'],
            ['Omeka\\Form\\UserForm', 'form.add_elements', $users, 'addUserQuotaFieldset'],
            ['Omeka\\Controller\\Admin\\User', 'view.details', $users, 'viewUserQuotaDetails'],
            ['Omeka\\Api\\Adapter\\UserAdapter', 'api.update.post', $users, 'handleUserQuotaForm'],
            ['Omeka\\Form\\SiteForm', 'form.add_elements', $sites, 'addSiteQuotaFieldset'],
            ['Omeka\\Controller\\SiteAdmin\\Index', 'view.show.after', $sites, 'viewSiteQuotaDetails'],
            ['Omeka\\Api\\Adapter\\SiteAdapter', 'api.update.post', $sites, 'handleSiteQuotaForm'],
            ['Omeka\\Controller\\SiteAdmin\\Index', 'site.save.post', $sites, 'handleSiteQuotaForm'],
        ];
        foreach ($expected as [$target, $event, $listener, $method]) {
            $registered = $events->getListeners([$target], $event);
            $callbacks = array_merge(...array_values($registered));
            $this->assertContains([$listener, $method], $callbacks);
            $this->assertIsCallable([$listener, $method]);
        }
    }

    public function testFactoryCreatesQuotaManager(): void
    {
        $config = $this->module->getServiceConfig();
        $factory = $config['factories']['DiskQuota\\DiskQuotaManager'];
        $this->assertInstanceOf(
            \DiskQuota\Service\DiskQuotaManager::class,
            $factory($this->serviceLocatorMock)
        );
    }
}

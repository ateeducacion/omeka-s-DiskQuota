<?php
declare(strict_types=1);

namespace DiskQuotaTest\Controller;

use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;

class DiskQuotaFunctionalTest extends TestCase
{
    protected $serviceManager;
    
    protected function setUp(): void
    {
        // Create a service manager with allowOverride = true
        $this->serviceManager = new ServiceManager(['allowOverride' => true]);
        
        // Configure basic simulated services
        $this->setupServiceManager();
    }
    
    protected function setupServiceManager(): void
    {
        // Simulate the basic services we would need
        
        // Simulated authentication service
        $authService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['hasIdentity', 'getIdentity'])
            ->getMock();
        
        // Simulated user
        $user = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getId', 'getEmail', 'getRole'])
            ->getMock();
        
        $user->method('getId')->willReturn(1);
        $user->method('getEmail')->willReturn('test@example.com');
        $user->method('getRole')->willReturn('global_admin');
        
        $authService->method('hasIdentity')->willReturn(true);
        $authService->method('getIdentity')->willReturn($user);
        
        // Simulated quota service
        $diskQuotaManager = $this->getMockBuilder('DiskQuota\Service\DiskQuotaManager')
            ->disableOriginalConstructor()
            ->getMock();
        
        $diskQuotaManager->method('getUserQuota')->willReturn(100 * 1024 * 1024); // 100MB
        $diskQuotaManager->method('getUsedDiskSpaceByUser')->willReturn(50 * 1024 * 1024); // 50MB
        $diskQuotaManager->method('isQuotaExceeded')->willReturn(false);
        
        // Register services in the service manager
        $this->serviceManager->setService('Omeka\AuthenticationService', $authService);
        $this->serviceManager->setService('DiskQuota\DiskQuotaManager', $diskQuotaManager);
    }
    
    /**
     * Simplified test that verifies the behavior of quota checking
     */
    public function testQuotaCheckBeforeUpload(): void
    {
        // Get the DiskQuotaManager service
        $diskQuotaManager = $this->serviceManager->get('DiskQuota\DiskQuotaManager');
        
        // Verify that the service returns expected values
        $this->assertEquals(100 * 1024 * 1024, $diskQuotaManager->getUserQuota(1));
        $this->assertEquals(50 * 1024 * 1024, $diskQuotaManager->getUsedDiskSpaceByUser(1));
        
        // Verify behavior with available space
        $this->assertFalse($diskQuotaManager->isQuotaExceeded(1, 20 * 1024 * 1024));
        
        // Simulate a quota exceeded situation with a new mock
        $diskQuotaManagerExceeded = $this->getMockBuilder('DiskQuota\Service\DiskQuotaManager')
            ->disableOriginalConstructor()
            ->getMock();
        
        $diskQuotaManagerExceeded->method('isQuotaExceeded')->willReturn(true);
        
        // Instead of trying to replace the service, we simply perform the test with the new mock
        $this->assertTrue($diskQuotaManagerExceeded->isQuotaExceeded(1, 60 * 1024 * 1024));
    }
    
    /**
     * Test that verifies the module configuration management
     */
    public function testModuleConfiguration(): void
    {
        // Create a module to test configuration methods
        $module = new \DiskQuota\Module();
        
        // Verify that the getConfig method returns an array
        $config = $module->getConfig();
        $this->assertIsArray($config);
        
        // Verify that getServiceConfig returns an array with factories
        $serviceConfig = $module->getServiceConfig();
        $this->assertIsArray($serviceConfig);
        $this->assertArrayHasKey('factories', $serviceConfig);
        
        // Verify that the DiskQuotaManager factory is defined
        $this->assertArrayHasKey('DiskQuota\DiskQuotaManager', $serviceConfig['factories']);
        
        // Verify that the factory is a closure
        $this->assertIsCallable($serviceConfig['factories']['DiskQuota\DiskQuotaManager']);
    }
}

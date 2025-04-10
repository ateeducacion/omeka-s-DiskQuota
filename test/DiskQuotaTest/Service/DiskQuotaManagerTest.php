<?php
declare(strict_types=1);
namespace DiskQuotaTest\Service;

use DiskQuota\Service\DiskQuotaManager;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;

class DiskQuotaManagerTest extends TestCase
{
    protected $diskQuotaManager;
    protected $serviceLocatorMock;
    
    protected function setUp(): void
    {
        // Create mocks for required services directly
        $statementMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['execute', 'fetchColumn', 'bindValue'])
            ->getMock();
        
        $statementMock->method('execute')->willReturn(true);
        $statementMock->method('fetchColumn')->willReturn(50 * 1024 * 1024); // 50MB
        $statementMock->method('bindValue')->willReturnSelf();
        
        $connectionMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['prepare'])
            ->getMock();
        
        $connectionMock->method('prepare')->willReturn($statementMock);
        
        $userSettingsMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['setTargetId', 'get'])
            ->getMock();
        
        $userSettingsMock->method('get')->willReturn(100); // 100MB
        $userSettingsMock->method('setTargetId')->willReturnSelf();
        
        $globalSettingsMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['get'])
            ->getMock();
        
        $globalSettingsMock->method('get')->willReturn(500); // 500MB
        
        // Crear mock del service locator
        $this->serviceLocatorMock = $this->createMock(ServiceManager::class);
        $this->serviceLocatorMock->method('get')
            ->will($this->returnValueMap([
                ['Omeka\Connection', $connectionMock],
                ['Omeka\Settings\User', $userSettingsMock],
                ['Omeka\Settings', $globalSettingsMock],
            ]));
        
        $this->diskQuotaManager = new DiskQuotaManager($this->serviceLocatorMock);
    }
    
    public function testGetUsedDiskSpaceByUser(): void
    {
        $result = $this->diskQuotaManager->getUsedDiskSpaceByUser(1);
        $this->assertEquals(50 * 1024 * 1024, $result);
    }
    
    public function testGetUserQuota(): void
    {
        $result = $this->diskQuotaManager->getUserQuota(1);
        // 100MB en bytes
        $this->assertEquals(100 * 1024 * 1024, $result);
    }
    
    public function testIsQuotaExceededWhenBelowLimit(): void
    {
        // El usuario ha usado 50MB y tiene 100MB de cuota, tratando de subir 20MB
        $result = $this->diskQuotaManager->isQuotaExceeded(1, 20 * 1024 * 1024);
        $this->assertFalse($result);
    }
    
    public function testIsQuotaExceededWhenAboveLimit(): void
    {
        // El usuario ha usado 50MB y tiene 100MB de cuota, tratando de subir 60MB (sobrepasa el límite)
        $result = $this->diskQuotaManager->isQuotaExceeded(1, 60 * 1024 * 1024);
        $this->assertTrue($result);
    }
    
    public function testIsQuotaExceededWithUnlimitedQuota(): void
    {
        // Usar el mismo enfoque de mock con stdClass
        $userSettingsMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['setTargetId', 'get'])
            ->getMock();
        $userSettingsMock->method('get')->willReturn(0); // 0 = ilimitado
        $userSettingsMock->method('setTargetId')->willReturnSelf();
        
        // Para asegurar que usamos el mismo tipo de mock para connectionMock
        $statementMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['execute', 'fetchColumn', 'bindValue'])
            ->getMock();
        $statementMock->method('execute')->willReturn(true);
        $statementMock->method('fetchColumn')->willReturn(0);
        $statementMock->method('bindValue')->willReturnSelf();
        
        $connectionMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['prepare'])
            ->getMock();
        $connectionMock->method('prepare')->willReturn($statementMock);
        
        $globalSettingsMock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['get'])
            ->getMock();
        $globalSettingsMock->method('get')->willReturn(0);
        
        $serviceLocatorMock = $this->createMock(ServiceManager::class);
        $serviceLocatorMock->method('get')
            ->will($this->returnValueMap([
                ['Omeka\Connection', $connectionMock],
                ['Omeka\Settings\User', $userSettingsMock],
                ['Omeka\Settings', $globalSettingsMock],
            ]));
        
        $diskQuotaManager = new DiskQuotaManager($serviceLocatorMock);
        
        // Con cuota ilimitada nunca debería exceder
        $result = $diskQuotaManager->isQuotaExceeded(1, 999999999);
        $this->assertFalse($result);
    }
}

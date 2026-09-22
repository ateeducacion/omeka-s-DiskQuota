<?php
declare(strict_types=1);

namespace DiskQuotaTest\Module;

use DiskQuota\Module;
use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;

class QuotaSettingsTest extends TestCase
{
    private function double(array $values)
    {
        $mock = $this->getMockBuilder(\stdClass::class)->addMethods(array_keys($values))->getMock();
        foreach ($values as $method => $value) {
            $mock->method($method)->willReturn($value);
        }
        return $mock;
    }

    /** @dataProvider settingsCases */
    public function testOnlyAdminsCanSaveNonnegativeQuotas(
        string $kind,
        string $key,
        ?string $role,
        ?int $quota,
        bool $resourceExists,
        bool $shouldSave
    ): void {
        $services = new ServiceManager();
        $actor = $role === null ? null : $this->double(['getRole' => $role]);
        $services->setService('Omeka\AuthenticationService', $this->double(['getIdentity' => $actor]));
        $settings = $this->getMockBuilder(\stdClass::class)->addMethods(['setTargetId', 'set'])->getMock();
        $settings->expects($shouldSave ? $this->once() : $this->never())->method('setTargetId')->with(7);
        $field = 'diskquota_' . strtolower($kind) . '_quota';
        $settings->expects($shouldSave ? $this->once() : $this->never())->method('set')->with($field, $quota);
        $services->setService('Omeka\\Settings\\' . $kind, $settings);
        $resource = $resourceExists ? $this->double(['getId' => 7]) : null;
        $event = new Event('api.update.post', null, [
            'request' => $this->double(['getContent' => $quota === null ? [] : [$key => [$field => $quota]]]),
            'response' => $this->double(['getContent' => $resource]),
        ]);
        $module = new Module();
        $module->setServiceLocator($services);
        $method = 'handle' . $kind . 'QuotaForm';
        $module->$method($event);
    }

    public function settingsCases(): array
    {
        $cases = [];
        foreach (['User' => ['user-settings'], 'Site' => ['site_settings', 'o:settings']] as $kind => $keys) {
            foreach ($keys as $key) {
                $cases[] = [$kind, $key, 'global_admin', 123, true, true];
                $cases[] = [$kind, $key, 'global_admin', 0, true, true];
                $cases[] = [$kind, $key, 'global_admin', -1, true, false];
                $cases[] = [$kind, $key, 'editor', 123, true, false];
                $cases[] = [$kind, $key, null, 123, true, false];
                $cases[] = [$kind, $key, 'global_admin', null, true, false];
                $cases[] = [$kind, $key, 'global_admin', 123, false, false];
            }
        }
        return $cases;
    }
}

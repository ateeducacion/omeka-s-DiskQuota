<?php
declare(strict_types=1);

namespace DiskQuotaTest\Module;

use DiskQuota\Module;
use DiskQuota\Service\DiskQuotaManager;
use DiskQuotaTest\SiteFixture;
use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceManager;
use PHPUnit\Framework\TestCase;

class QuotaPresentationTest extends TestCase
{
    use SiteFixture;

    private function double(array $values)
    {
        $double = $this->getMockBuilder(\stdClass::class)->addMethods(array_keys($values))->getMock();
        foreach ($values as $method => $value) {
            $double->method($method)->willReturn($value);
        }
        return $double;
    }

    private function settings(int $target, int $quota)
    {
        $settings = $this->double(['get' => $quota, 'setTargetId' => null]);
        $settings->expects($this->once())->method('setTargetId')->with($target);
        return $settings;
    }

    /** @dataProvider siteDisplayCases */
    public function testSiteDisplaysUseOnlyAssignedOriginalMedia(bool $formPage, bool $admin, int $siteId): void
    {
        $site = $this->double(['id' => $siteId]);
        $services = new ServiceManager();
        $services->setService('Omeka\Connection', $this->createSiteDatabase());
        $services->setService('Omeka\Settings', new \Omeka\Settings());
        $services->setService('Omeka\Settings\Site', $this->settings($siteId, 1000));
        $services->setService('DiskQuota\DiskQuotaManager', new DiskQuotaManager($services));
        $view = $this->double(['partial' => '<p>Usage</p>']);
        $view->expects($this->once())->method('partial')->with('common/site-quota-usage', [
            'site' => $site,
            'siteQuota' => 1000,
            'currentUsage' => $siteId === 10 ? 500 : 0,
            'mediaCount' => $siteId === 10 ? 3 : 0,
        ]);
        $module = new \DiskQuota\Listener\SiteQuotaListener($services);
        if ($formPage) {
            $plugins = new ServiceManager();
            $plugins->setService('currentSite', function () use ($site) {
                return $site;
            });
            $services->setService('ControllerPluginManager', $plugins);
            $services->setService('Omeka\EntityManager', $this->double(['find' => $site]));
            $user = $this->double(['getRole' => $admin ? 'global_admin' : 'editor']);
            $services->setService('Omeka\AuthenticationService', $this->double(['getIdentity' => $user]));
            $services->setService('ViewRenderer', $view);
            $form = new \Omeka\Form\SiteForm();
            ob_start();
            try {
                $module->addSiteQuotaFieldset(new Event('form.add_elements', $form));
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $fieldset = $form->get('site_settings');
            $name = $admin ? 'diskquota_site_quota' : 'diskquota_site_quota_display';
            $this->assertSame($admin ? 1000 : '1000 MB', $fieldset->get($name)->getValue());
            $this->assertSame(!$admin, (bool) $fieldset->get($name)->getAttribute('readonly'));
            $this->assertStringContainsString($name, $html);
            $this->assertStringContainsString('<p>Usage</p>', $html);
        } else {
            $view->site = $site;
            $this->expectOutputString('<p>Usage</p>');
            $module->viewSiteQuotaDetails(new Event('view.show.after', $view));
        }
    }

    public function siteDisplayCases(): array
    {
        return [
            'admin edit' => [true, true, 10],
            'editor edit' => [true, false, 10],
            'show' => [false, false, 10],
            'item set alone edit' => [true, true, 40],
            'item set alone show' => [false, false, 40],
        ];
    }

    /** @dataProvider userDisplayCases */
    public function testUserDisplaysUseOwnerUsageAndRespectRole(bool $formPage, bool $admin, bool $apiFails): void
    {
        $user = $this->double(['id' => 7]);
        $manager = $this->createMock(DiskQuotaManager::class);
        $manager->expects($this->once())->method('getUsedDiskSpaceByUser')->with(7)->willReturn(123);
        $api = $this->double(['search' => $this->double(['getTotalResults' => 2])]);
        if ($apiFails) {
            $api = $this->getMockBuilder(\stdClass::class)->addMethods(['search'])->getMock();
            $api->method('search')->willThrowException(new \RuntimeException('Search unavailable'));
        }
        $services = new ServiceManager();
        $services->setService('Omeka\Settings', new \Omeka\Settings());
        $services->setService('Omeka\Settings\User', $this->settings(7, 500));
        $services->setService('DiskQuota\DiskQuotaManager', $manager);
        $services->setService('Omeka\ApiManager', $api);
        $view = $this->double(['partial' => '<p>User usage</p>', 'translate' => 'Unknown']);
        $view->expects($this->once())->method('partial')->with('common/user-quota-usage', [
            'user' => $user, 'userQuota' => 500, 'currentUsage' => 123,
            'mediaCount' => $apiFails ? 'Unknown' : 2,
        ]);
        $module = new \DiskQuota\Listener\UserQuotaListener($services);
        if ($formPage) {
            $services->setService('Omeka\EntityManager', $this->double(['find' => $user]));
            $actor = $this->double(['getRole' => $admin ? 'global_admin' : 'editor']);
            $services->setService('Omeka\AuthenticationService', $this->double(['getIdentity' => $actor]));
            $services->setService('ViewRenderer', $view);
            $form = new \Omeka\Form\UserForm(null, ['user_id' => 7]);
            $form->add(['name' => 'user-settings', 'type' => 'fieldset']);
            ob_start();
            try {
                $module->addUserQuotaFieldset(new Event('form.add_elements', $form));
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $name = $admin ? 'diskquota_user_quota' : 'diskquota_user_quota_display';
            $field = $form->get('user-settings')->get($name);
            $this->assertSame($admin ? 500 : '500 MB', $field->getValue());
            $this->assertSame(!$admin, (bool) $field->getAttribute('readonly'));
            $this->assertStringContainsString($name, $html);
            $this->assertStringContainsString('<p>User usage</p>', $html);
        } else {
            $view->resource = $user;
            $this->expectOutputString('<p>User usage</p>');
            $module->viewUserQuotaDetails(new Event('view.details', $view));
        }
    }

    public function userDisplayCases(): array
    {
        return [[true, true, false], [true, false, true], [false, false, false], [false, false, true]];
    }

    public function testUnrelatedFormsAndMissingSiteAreIgnored(): void
    {
        $services = new ServiceManager();
        $users = new \DiskQuota\Listener\UserQuotaListener($services);
        $sites = new \DiskQuota\Listener\SiteQuotaListener($services);
        $event = new Event('form.add_elements', new \Laminas\Form\Form());
        $users->addUserQuotaFieldset($event);
        $sites->addSiteQuotaFieldset($event);
        $form = new \Omeka\Form\SiteForm();
        $sites->addSiteQuotaFieldset(new Event('form.add_elements', $form));
        $this->assertFalse($form->has('site_settings'));
        $view = new \stdClass();
        $view->site = null;
        $this->expectOutputString('');
        $sites->viewSiteQuotaDetails(new Event('view.show.after', $view));
    }

    public function testConfigFormRendersStoredValuesAndDefaults(): void
    {
        $form = new \DiskQuota\Form\ConfigForm();
        $form->init();
        $services = new ServiceManager();
        $services->setService('Config', []);
        $services->setService('Omeka\Settings', new \Omeka\Settings());
        $services->setService('FormElementManager', $this->double(['get' => $form]));
        $module = new Module();
        $module->setServiceLocator($services);
        $renderer = $this->getMockBuilder(\Laminas\View\Renderer\PhpRenderer::class)
            ->addMethods(['formCollection'])->getMock();
        $renderer->expects($this->once())->method('formCollection')->with($form)->willReturn('<form></form>');
        $this->assertSame('<form></form>', $module->getConfigForm($renderer));
        $this->assertSame(1000, $form->get('diskquota_default_site_quota')->getValue());
        $this->assertSame(500, $form->get('diskquota_default_user_quota')->getValue());
        $this->assertSame(10000, $form->get('diskquota_default_global_quota')->getValue());
        $this->assertSame(15, $form->get('diskquota_warning_threshold')->getValue());
    }
}

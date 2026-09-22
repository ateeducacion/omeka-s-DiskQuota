<?php
declare(strict_types=1);

namespace DiskQuota;

use DiskQuota\Listener\SiteQuotaListener;
use DiskQuota\Listener\UploadQuotaListener;
use DiskQuota\Listener\UserQuotaListener;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    /**
     * Get configuration for this module.
     *
     * @return array The module configuration array
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }
    
    /**
     * Get the service configuration.
     *
     * Defines the service factories used by this module.
     *
     * @return array Service configuration array
     */
    public function getServiceConfig()
    {
        $factories = [
            'DiskQuota\DiskQuotaManager' => function ($services) {
                return new Service\DiskQuotaManager($services);
            },
        ];
        foreach ([UploadQuotaListener::class, UserQuotaListener::class, SiteQuotaListener::class] as $class) {
            $factories[$class] = function ($services) use ($class) {
                return new $class($services);
            };
        }
        return ['factories' => $factories];
    }

    /**
     * Install this module.
     *
     * Executed when the module is first installed.
     *
     * @param ServiceLocatorInterface $serviceLocator The service locator
     */
    public function install(ServiceLocatorInterface $serviceLocator)
    {
    }

    /**
     * Uninstall this module.
     *
     * Executed when the module is uninstalled.
     *
     * @param ServiceLocatorInterface $serviceLocator The service locator
     */
    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
    }

    /**
     * Attach all listeners for disk quota checks and form management.
     *
     * This method registers all event listeners needed for quota enforcement
     * and user interface integration.
     *
     * @param SharedEventManagerInterface $sharedEventManager The shared event manager
     */
    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {

        $services = $this->getServiceLocator();
        $uploads = $services->get(UploadQuotaListener::class);
        $users = $services->get(UserQuotaListener::class);
        $sites = $services->get(SiteQuotaListener::class);

        // Listen for media creation to check user quota
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.hydrate.pre',
            [$uploads, 'checkUserQuotaBeforeUpload']
        );
        
    // Also attach to all item adapter events since files are often uploaded as part of items
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.hydrate.pre',
            [$uploads, 'checkUserQuotaBeforeUpload']
        );

        // Also attach to the api.create.pre event for Media to catch direct API uploads
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.create.pre',
            [$uploads, 'checkUserQuotaBeforeUpload']
        );

        // Add disk quota tab to user edit page
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_elements',
            [$users, 'addUserQuotaFieldset']
        );
        
        // Handle user quota form submission
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\User',
            'view.details',
            [$users, 'viewUserQuotaDetails']
        );
        
        // Save user quota settings after user update
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\UserAdapter',
            'api.update.post',
            [$users, 'handleUserQuotaForm']
        );
        
        // Add disk quota tab to site edit page
        $sharedEventManager->attach(
            \Omeka\Form\SiteForm::class,
            'form.add_elements',
            [$sites, 'addSiteQuotaFieldset']
        );
        
        // Display site quota details on site show page
        $sharedEventManager->attach(
            'Omeka\Controller\SiteAdmin\Index',
            'view.show.after',
            [$sites, 'viewSiteQuotaDetails']
        );
        
        // Save site quota settings after site update
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\SiteAdapter',
            'api.update.post',
            [$sites, 'handleSiteQuotaForm']
        );
        
        // Also attach to the site.save.post event for form submissions
        $sharedEventManager->attach(
            'Omeka\Controller\SiteAdmin\Index',
            'site.save.post',
            [$sites, 'handleSiteQuotaForm']
        );
        
        // Check site quota before upload
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.create.pre',
            [$uploads, 'checkSiteQuotaBeforeUpload']
        );
    }

    /**
     * Get the module's configuration form.
     *
     * Generates the HTML for the module configuration form in the admin interface.
     *
     * @param PhpRenderer $renderer The view renderer
     * @return string HTML markup for the configuration form
     */
    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $settings = $services->get('Omeka\Settings');
        
        $formManager = $services->get('FormElementManager');
        $form = $formManager->get(Form\ConfigForm::class);
        
        // Set default values from settings
        $data = [];
        $defaultSettings = [
            'diskquota_default_site_quota' => 1000,    // 1GB
            'diskquota_default_user_quota' => 500,     // 500MB
            'diskquota_default_global_quota' => 10000, // 10GB
            'diskquota_warning_threshold' => 15,       // 15%
        ];
        
        foreach ($defaultSettings as $key => $default) {
            $data[$key] = $settings->get($key, $default);
        }
        
        $form->setData($data);
        
        $html = $renderer->formCollection($form);
        return $html;
    }

    /**
     * Handle the module's configuration form.
     *
     * Processes the submitted configuration form data and saves settings.
     *
     * @param AbstractController $controller The controller that handled the request
     * @return bool True if form was handled successfully, false otherwise
     */
    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(Form\ConfigForm::class);
        
        $params = $controller->getRequest()->getPost();
        
        $form->setData($params);
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }
        
        $formData = $form->getData();
        
        // Save settings
        $settings->set('diskquota_default_site_quota', $formData['diskquota_default_site_quota']);
        $settings->set('diskquota_default_user_quota', $formData['diskquota_default_user_quota']);
        $settings->set('diskquota_default_global_quota', $formData['diskquota_default_global_quota']);
        $settings->set('diskquota_warning_threshold', $formData['diskquota_warning_threshold']);
        
        $controller->messenger()->addSuccess('Disk quota settings successfully updated'); // @translate
        
        return true;
    }
}

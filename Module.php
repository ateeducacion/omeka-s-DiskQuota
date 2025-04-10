<?php
declare(strict_types=1);

namespace DiskQuota;

use Interop\Container\ContainerInterface;
use Laminas\EventManager\EventManager;
use Laminas\EventManager\EventManagerAwareInterface;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\SharedEventManager;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Navigation;
use Laminas\Navigation\AbstractContainer;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Entity\Media;
use Omeka\Entity\Site;
use Omeka\Permissions\Assertion\SiteAssertion;
use Omeka\Stdlib\Message;

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
        return [
            'factories' => [
                'DiskQuota\DiskQuotaManager' => function ($services) {
                    return new Service\DiskQuotaManager($services);
                },
            ],
        ];
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

        // Listen for media creation to check user quota
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.hydrate.pre',
            [$this, 'checkUserQuotaBeforeUpload']
        );
        
    // Also attach to all item adapter events since files are often uploaded as part of items
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.hydrate.pre',
            [$this, 'checkUserQuotaBeforeUpload']
        );

        // Also attach to the api.create.pre event for Media to catch direct API uploads
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.create.pre',
            [$this, 'checkUserQuotaBeforeUpload']
        );

        // Add disk quota tab to user edit page
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_elements',
            [$this, 'addUserQuotaFieldset']
        );
        
        // Handle user quota form submission
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\User',
            'view.details',
            [$this, 'viewUserQuotaDetails']
        );
        
        // Save user quota settings after user update
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\UserAdapter',
            'api.update.post',
            [$this, 'handleUserQuotaForm']
        );
        
        // Add disk quota tab to site edit page
        $sharedEventManager->attach(
            \Omeka\Form\SiteForm::class,
            'form.add_elements',
            [$this, 'addSiteQuotaFieldset']
        );
        
        // Display site quota details on site show page
        $sharedEventManager->attach(
            'Omeka\Controller\SiteAdmin\Index',
            'view.show.after',
            [$this, 'viewSiteQuotaDetails']
        );
        
        // Save site quota settings after site update
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\SiteAdapter',
            'api.update.post',
            [$this, 'handleSiteQuotaForm']
        );
        
        // Also attach to the site.save.post event for form submissions
        $sharedEventManager->attach(
            'Omeka\Controller\SiteAdmin\Index',
            'site.save.post',
            [$this, 'handleSiteQuotaForm']
        );
        
        // Check site quota before upload
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.create.pre',
            [$this, 'checkSiteQuotaBeforeUpload']
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

    /**
     * Check if an upload would exceed the user's quota.
     * 
     * Examines file uploads to determine if they would exceed the user's disk quota.
     * If quota would be exceeded, adds an error message and prevents the upload.
     *
     * @param Event $event The event that triggered this callback
     * @return void
     */
    public function checkUserQuotaBeforeUpload($event)
    {
        // Get services
        $services = $this->getServiceLocator();
    
        // Get the currently authenticated user
        $auth = $services->get('Omeka\AuthenticationService');
        $user = $auth->getIdentity();
    
        // Skip if no authenticated user
        if (!$user) {
            return;
        }
    
        $userId = $user->getId();
    
        // Get request from the event
        $request = $event->getParam('request');
    
        // Only enforce quota checks on resource creation to avoid false positives on updates.
        if ($request->getOperation() !== 'create') {
            return;
        }
    
        // Initialize file size
        $fileSize = 0;
    
        // Try to get file size from HTTP request
        $httpRequest = $services->get('Request');
        if ($httpRequest instanceof \Laminas\Http\Request) {
            $files = $httpRequest->getFiles()->toArray();
            if (!empty($files)) {
                // Traverse the files array to find the first file
                foreach ($files as $fileData) {
                    if (is_array($fileData) && !empty($fileData['tmp_name']) &&
                            file_exists($fileData['tmp_name'])) {
                        $fileSize = filesize($fileData['tmp_name']);
                        break;
                    } elseif (is_array($fileData)) {
                        // Handle nested file arrays
                        foreach ($fileData as $nestedFile) {
                            if (is_array($nestedFile) && !empty($nestedFile['tmp_name']) &&
                                    file_exists($nestedFile['tmp_name'])) {
                                $fileSize = filesize($nestedFile['tmp_name']);
                                break 2;
                            }
                        }
                    }
                }
            }
        }
    
        // If we couldn't get file size from HTTP request, try to infer it from content data
        if ($fileSize <= 0) {
            $data = $request->getContent();
        
            // Check if there's a 'data' field with a size property (for OmekaS API uploads)
            if (!empty($data['data']) && !empty($data['data']['size'])) {
                $fileSize = (int)$data['data']['size'];
            } elseif (!empty($data['o:size'])) {
                // Check for o:size field, which might contain the file size for already processed uploads
                $fileSize = (int)$data['o:size'];
            }
        }
    
        // Log for debugging
        error_log('DiskQuota: Checking file upload with size: ' . $fileSize . ' bytes for user ID: ' . $userId);
    
        // Skip if no file size could be determined
        if ($fileSize <= 0) {
            return;
        }
    
        // Get the disk quota manager
        $diskQuotaManager = $services->get('DiskQuota\DiskQuotaManager');
    
        // Check if upload would exceed quota using existing manager
        if ($diskQuotaManager->isQuotaExceeded($userId, $fileSize)) {
            // Get the user's quota and current usage for the error message
            $quota = $diskQuotaManager->getUserQuota($userId);
            $usedSpace = $diskQuotaManager->getUsedDiskSpaceByUser($userId);
        
            // Format sizes for display
            $usedMB = round($usedSpace / (1024 * 1024), 2);
            $quotaMB = round($quota / (1024 * 1024), 2);
            $fileSizeMB = round($fileSize / (1024 * 1024), 2);
        
            // Log the error
            $msg = sprintf(
                'DiskQuota: Upload rejected. File size: %s MB, Used: %s MB, Limit: %s MB',
                $fileSizeMB,
                $usedMB,
                $quotaMB
            );
            error_log($msg);
        
            // If quota exceeded, add error message and block upload
            $errorStore = $event->getParam('errorStore');
            if ($errorStore) {
                // In Omeka S, the Message class expects the message string and then the params separately
                $errorStore->addError('file', new \Omeka\Stdlib\Message(
                    'Upload rejected: quota exceeded. File: %s MB, Used: %s MB, Limit: %s MB',
                    // Make sure to pass each parameter individually, not as an array
                    $fileSizeMB,
                    $usedMB,
                    $quotaMB
                ));
            }
        }
    }

    /**
     * Check if an upload would exceed the site's quota.
     * 
     * Examines file uploads to determine if they would exceed the site's disk quota.
     * If quota would be exceeded, adds an error message and prevents the upload.
     * 
     * @param Event $event The event that triggered this callback
     * @return void
     */
    public function checkDiskQuotaBeforeUpload($event)
    {
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $request = $event->getParam('request');
        $fileData = $request->getFiles();
        
        // Skip if no file uploaded
        if (empty($fileData)) {
            return;
        }

        // Get the item's site ID
        $item = $api->read('items', $request->getValue('o:item')->getId())->getContent();
        $itemSets = $item->itemSets();
        
        // Find the site this item belongs to
        $siteId = null;
        foreach ($itemSets as $itemSet) {
            $sites = $api->search('sites', [
                'item_set_id' => $itemSet->id(),
            ])->getContent();
            
            if (count($sites) > 0) {
                $siteId = $sites[0]->id();
                break;
            }
        }
        
        // Also check if the item is directly assigned to a site
        if (!$siteId) {
            $sites = $api->search('sites', [
                'item_id' => $item->id(),
            ])->getContent();
            
            if (count($sites) > 0) {
                $siteId = $sites[0]->id();
            }
        }
        
        if (!$siteId) {
            // No site assigned, no quota to check
            return;
        }
        
        // Get the site's quota information
        try {
            $connection = $this->getServiceLocator()->get('Omeka\Connection');
            $stmt = $connection->prepare('SELECT quota_size, current_usage FROM site_quota WHERE site_id = ?');
            $stmt->bindValue(1, $siteId);
            $stmt->execute();
            $quotaInfo = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$quotaInfo) {
                // No quota set for this site
                return;
            }
            
            // Calculate file size from the uploaded file
            $fileSize = filesize($fileData['file']['tmp_name']);
            
            // Check if upload would exceed quota
            if ($quotaInfo['current_usage'] + $fileSize > $quotaInfo['quota_size']) {
                $errorStore = $event->getParam('errorStore');
                $errorStore->addError('file', new Message(
                    'File upload rejected: Would exceed site quota. Current usage: %s MB, Limit: %s MB',
                    [round($quotaInfo['current_usage'] / (1024 * 1024), 2),
                        round($quotaInfo['quota_size'] / (1024 * 1024), 2)]
                ));
                return;
            }
        } catch (\Exception $e) {
            // Log error but allow upload
            error_log('DiskQuota: ' . $e->getMessage());
        }
    }

    /**
     * Add quota fields to the user edit form.
     * 
     * Adds quota management fields to the user edit form and displays current usage.
     * Administrators can modify quotas while regular users see read-only information.
     * 
     * @param Event $event The form event
     * @return void
     */
    public function addUserQuotaFieldset($event)
    {
        $form = $event->getTarget();

        // Check if we have a valid form instance
        if (!$form instanceof \Omeka\Form\UserForm) {
            return;
        }

        $services = $this->getServiceLocator();

        // Get the disk quota manager
        $diskQuotaManager = $services->get('DiskQuota\DiskQuotaManager');

        // Get the user ID from the form options
        $options = $form->getOptions();
        $userId = isset($options['user_id']) ? (int)$options['user_id'] : null;

        // Get user entity if we have an ID
        $entityManager = $services->get('Omeka\EntityManager');
        $user = $userId ? $entityManager->find('Omeka\Entity\User', $userId) : null;

        // Get default user quota from global settings
        $globalSettings = $services->get('Omeka\Settings');
        $defaultQuota = $globalSettings->get('diskquota_default_user_quota', 500); // Default 500MB

        // Initialize variables
        $userQuota = $defaultQuota;
        $currentUsage = 0;
        $mediaCount = 0;

        // If we have a user, get their specific quota and usage
        if ($user) {
            $settings = $services->get('Omeka\Settings\User');
            $settings->setTargetId($userId);
    
            // Get user's quota or use default
            $userQuota = $settings->get('diskquota_user_quota', $defaultQuota);
    
            // Calculate user's current usage using the manager
            $currentUsage = $diskQuotaManager->getUsedDiskSpaceByUser($userId);
        
            // Get user's files count
            try {
                $apiManager = $services->get('Omeka\ApiManager');
                $response = $apiManager->search('media', ['owner_id' => $userId]);
                $mediaCount = $response->getTotalResults();
            } catch (\Exception $e) {
                $mediaCount = 'Unknown';
            }
        }


        // Check if current user is admin (can edit quota)
        $currentUser = $services->get('Omeka\AuthenticationService')->getIdentity();
        $isAdmin = $currentUser && $currentUser->getRole() === 'global_admin';

        // Add quota field to the user-settings fieldset
        $userSettings = $form->get('user-settings');

        if ($isAdmin) {
            // Add editable number input for admins
            $userSettings->add([
            'name' => 'diskquota_user_quota',
            'type' => 'Number',
            'options' => [
                'label' => 'User Quota (MB)', // @translate
                'info' => 'Set the disk quota for this user in megabytes. Set to 0 for unlimited.', // @translate
            ],
            'attributes' => [
                'id' => 'diskquota_user_quota',
                'min' => 0,
                'step' => 1,
                'value' => $userQuota,
                'required' => false,
            ],
            ]);
        } else {
         // Add read-only text display for non-admins
            $userSettings->add([
            'name' => 'diskquota_user_quota_display',
            'type' => 'Text',
            'options' => [
                'label' => 'User Quota (MB)', // @translate
                'info' => 'Disk quota allocated to this user.', // @translate
            ],
            'attributes' => [
                'id' => 'diskquota_user_quota_display',
                'readonly' => true,
                'value' => $userQuota . ' MB', // Display with unit
            ],
            ]);
        }

    // Only add the quota display if we have a user (for editing existing users)
        if ($user && $userQuota > 0) {
            // Get the view renderer
            $view = $services->get('ViewRenderer');
        
            // Render the same partial used in viewUserQuotaDetails
            $html = $view->partial(
                'common/user-quota-usage',
                [
                'user' => $user,
                'userQuota' => $userQuota,
                'currentUsage' => $currentUsage,
                'mediaCount' => $mediaCount,
                ]
            );
        
            // Escape the HTML for JavaScript
            $escapedHtml = str_replace(
                ["\n", "\r", "'", '"'],
                ['', '', "\\'", '\\"'],
                $html
            );

            // Determine the target ID based on admin status
            $targetId = $isAdmin ? 'diskquota_user_quota' : 'diskquota_user_quota_display';

            // Add script to append the HTML inside the quota field's container
            echo '
        <script>
            document.addEventListener("DOMContentLoaded", function() {
                var quotaField = document.getElementById("' . $targetId . '");
                if (quotaField) {
                    var fieldContainer = quotaField.closest(".field");
                    if (fieldContainer) {
                        // Target the inputs div within the field container
                        var inputsDiv = fieldContainer.querySelector(".inputs");
                        if (inputsDiv) {
                            var htmlContent = \'' . $escapedHtml . '\';
                            inputsDiv.insertAdjacentHTML("beforeend", htmlContent);
                            // Force style for override the flex from sidebar
                            var style = document.createElement("style");
                            style.textContent = `
                                .show .property, .meta-group {
                                    display: block !important;
                                    justify-content: left !important;
                                }
                            }`;
                            document.head.appendChild(style);           
                        }
                    }
                }
            });
        </script>';
        }
    }

        
    /**
     * Display user quota details on the user page.
     * 
     * Renders the user's quota usage information on the user details page.
     * Shows current usage, total quota, and file count.
     * 
     * @param Event $event The view event
     * @return void
     */
    public function viewUserQuotaDetails($event)
    {
        $view = $event->getTarget();
        $user = $view->resource;
        $services = $this->getServiceLocator();
        
        // Get the disk quota manager
        $diskQuotaManager = $services->get('DiskQuota\DiskQuotaManager');
        
        // Get user's quota information
        $settings = $services->get('Omeka\Settings\User');
        $settings->setTargetId($user->id());
        
        // Get default user quota from global settings
        $globalSettings = $services->get('Omeka\Settings');
        $defaultQuota = $globalSettings->get('diskquota_default_user_quota', 500); // Default 500MB
        
        // Get user's quota or use default
        $userQuota = $settings->get('diskquota_user_quota', $defaultQuota);
        
        // Calculate user's current usage using the manager
        $currentUsage = $diskQuotaManager->getUsedDiskSpaceByUser($user->id());


        // Get user's files mediaCount
        try {
            $apiManager = $services->get('Omeka\ApiManager');
            $response = $apiManager->search('media', ['owner_id' => $user->id()]);
            $mediaCount = $response->getTotalResults();
        } catch (\Exception $e) {
            $mediaCount = $view->translate('Unknown');
        }

        echo $view->partial(
            'common/user-quota-usage',
            [
                'user' => $user,
                'userQuota' => $userQuota,
                'currentUsage' => $currentUsage,
                'mediaCount' => $mediaCount,
            ]
        );
    }
    
    /**
     * Handle user quota form submission.
     * 
     * Processes the submitted user quota settings and saves them.
     * Only administrators can modify quota values.
     * 
     * @param Event $event The API event
     * @return void
     */
    public function handleUserQuotaForm($event)
    {
        $response = $event->getParam('response');
        $user = $response->getContent();
        
        if (!$user) {
            return;
        }
        
        $request = $event->getParam('request');
        $data = $request->getContent();
        
        // Only admins are allowed to edit quotas
        $services = $this->getServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');
        $currentUser = $auth->getIdentity();


        // Verify if the user has admin role
        if (!$currentUser || $currentUser->getRole() !== 'global_admin') {
            return; // Block if not admin
        }


        // Check if the quota field was submitted
        if (isset($data['user-settings']['diskquota_user_quota'])) {
            $userQuota = (int) $data['user-settings']['diskquota_user_quota'];
            
            // Validate quota (must be non-negative)
            if ($userQuota < 0) {
                return;
            }
            
            // Save the user quota
            $services = $this->getServiceLocator();
            $settings = $services->get('Omeka\Settings\User');
            $settings->setTargetId($user->getId());
            $settings->set('diskquota_user_quota', $userQuota);
        }
    }
    
    /**
     * Add site quota fieldset to site form.
     * 
     * Adds quota management fields to the site edit form and displays current usage.
     * Administrators can modify quotas while regular users see read-only information.
     * 
     * @param Event $event The form event
     * @return void
     */
    public function addSiteQuotaFieldset($event)
    {
        $form = $event->getTarget();
        
        // Check if we have a valid form instance
        if (!$form instanceof \Omeka\Form\SiteForm) {
            return;
        }
        
        $services = $this->getServiceLocator();

        // Get the site ID from the controller
        if ($services->has('ControllerPluginManager')) {
            $plugins = $services->get('ControllerPluginManager');
            if ($plugins->has('currentSite')) {
                $currentSite = $plugins->get('currentSite');
                $site = $currentSite();
                if ($site) {
                    $siteId = $site->id();
                }
            }
        }

        // When creating a site we don't have the needed info, so we exit and force the user to add the quote later
        if (empty($siteId)) {
            return;
        }



        // Get the disk quota manager
        $diskQuotaManager = $services->get('DiskQuota\DiskQuotaManager');
    
        error_log('DiskQuota: Adding site quota fieldset for site ID: ' . $siteId);
        
        // Get site entity if we have an ID
        $entityManager = $services->get('Omeka\EntityManager');
        $site = $siteId ? $entityManager->find('Omeka\Entity\Site', $siteId) : null;
        

        // Get default site quota from global settings
        $globalSettings = $services->get('Omeka\Settings');
        $defaultQuota = $globalSettings->get('diskquota_default_site_quota', 1000); // Default 1GB
        
        // Initialize variables
        $siteQuota = $defaultQuota;
        $currentUsage = 0;
        $mediaCount = 0;
        
        // If we have a site, get its specific quota and usage
        if ($site) {
            $siteSettings = $services->get('Omeka\Settings\Site');
            $siteSettings->setTargetId($siteId);
            
            // Get site's quota or use default - add debug logging
            $siteQuota = $siteSettings->get('diskquota_site_quota', $defaultQuota);



            error_log('DiskQuota: Retrieved site quota for site ID ' . $siteId . ': ' . $siteQuota);
            
            // Calculate site's current usage using the manager
            $currentUsage = $diskQuotaManager->getUsedDiskSpaceBySite($siteId);
            
            // Get site's files count
            try {
                $connection = $services->get('Omeka\Connection');
                $stmt = $connection->prepare('
                    SELECT COUNT(DISTINCT m.id) 
                    FROM media m
                    JOIN item i ON m.item_id = i.id
                    LEFT JOIN item_item_set iis ON iis.item_id = i.id
                    LEFT JOIN site_item_set sis ON sis.item_set_id = iis.item_set_id
                    LEFT JOIN item_site si ON si.item_id = i.id
                    WHERE (sis.site_id = ? OR si.site_id = ?) AND m.has_original = 1
                ');
                $stmt->bindValue(1, $siteId);
                $stmt->bindValue(2, $siteId);
                $stmt->execute();
                $mediaCount = $stmt->fetchColumn();
            } catch (\Exception $e) {
                $mediaCount = 'Unknown';
            }
        }
        
        // Check if current user is admin (can edit quota)
        $currentUser = $services->get('Omeka\AuthenticationService')->getIdentity();
        $isAdmin = $currentUser && $currentUser->getRole() === 'global_admin';
        
        // Create a fieldset for our settings if it doesn't exist
        if (!$form->has('site_settings')) {
            $form->add([
                'name' => 'site_settings',
                'type' => 'fieldset',
            ]);
        }
        
        // Add quota field to the site form
        $fieldset = $form->get('site_settings');
        
        if ($isAdmin) {
            // Add editable number input for admins
            $fieldset->add([
                'name' => 'diskquota_site_quota',
                'type' => 'Number',
                'options' => [
                    'label' => 'Site Quota (MB)', // @translate
                    'info' => 'Set the disk quota for this site in megabytes. Set to 0 for unlimited.', // @translate
                ],
                'attributes' => [
                    'id' => 'diskquota_site_quota',
                    'min' => 0,
                    'step' => 1,
                    'value' => $siteQuota,
                    'required' => false,
                ],
            ]);
            
            // Force the value to be set correctly
            $fieldset->get('diskquota_site_quota')->setValue($siteQuota);
            error_log('DiskQuota: Set form field value to ' . $siteQuota);
        } else {
            // Add read-only text display for non-admins
            $fieldset->add([
                'name' => 'diskquota_site_quota_display',
                'type' => 'Text',
                'options' => [
                    'label' => 'Site Quota (MB)', // @translate
                    'info' => 'Disk quota allocated to this site.', // @translate
                ],
                'attributes' => [
                    'id' => 'diskquota_site_quota_display',
                    'readonly' => true,
                    'value' => $siteQuota . ' MB', // Display with unit
                ],
            ]);
        }
        
        // Only add the quota display if we have a site (for editing existing sites)
        if ($site && $siteQuota > 0) {
            // Get the view renderer
            $view = $services->get('ViewRenderer');
            
            // Render the quota usage partial
            $html = $view->partial(
                'common/site-quota-usage',
                [
                    'site' => $site,
                    'siteQuota' => $siteQuota,
                    'currentUsage' => $currentUsage,
                    'mediaCount' => $mediaCount,
                ]
            );
            
            // Escape the HTML for JavaScript
            $escapedHtml = str_replace(
                ["\n", "\r", "'", '"'],
                ['', '', "\\'", '\\"'],
                $html
            );
            
            // Determine the target ID based on admin status
            $targetId = $isAdmin ? 'diskquota_site_quota' : 'diskquota_site_quota_display';
            
            // Add script to append the HTML inside the quota field's container
            echo '
            <script>
                document.addEventListener("DOMContentLoaded", function() {
                    var quotaField = document.getElementById("' . $targetId . '");
                    if (quotaField) {
                        var fieldContainer = quotaField.closest(".field");
                        if (fieldContainer) {
                            var inputsDiv = fieldContainer.querySelector(".inputs");
                            if (inputsDiv) {
                                var htmlContent = \'' . $escapedHtml . '\';
                                inputsDiv.insertAdjacentHTML("beforeend", htmlContent);
                            }
                        }
                    }
                });
            </script>';
        }
    }
    
    /**
     * Display site quota details on the site page.
     * 
     * Renders the site's quota usage information on the site details page.
     * Shows current usage, total quota, and file count.
     * 
     * @param Event $event The view event
     * @return void
     */
    public function viewSiteQuotaDetails($event)
    {
        $view = $event->getTarget();
        $site = $view->site;
        
        if (!$site) {
            return;
        }
        
        $services = $this->getServiceLocator();
        
        // Get the disk quota manager
        $diskQuotaManager = $services->get('DiskQuota\DiskQuotaManager');
        
        // Get site's quota information
        $siteSettings = $services->get('Omeka\Settings\Site');
        $siteSettings->setTargetId($site->id());
        
        // Get default site quota from global settings
        $globalSettings = $services->get('Omeka\Settings');
        $defaultQuota = $globalSettings->get('diskquota_default_site_quota', 1000); // Default 1GB
        
        // Get site's quota or use default
        $siteQuota = $siteSettings->get('diskquota_site_quota', $defaultQuota);
        
        // Calculate site's current usage using the manager
        $currentUsage = $diskQuotaManager->getUsedDiskSpaceBySite($site->id());
        
        // Get site's files count
        try {
            $connection = $services->get('Omeka\Connection');

            $sql = "
                SELECT COUNT(DISTINCT m.id) AS total_media
                FROM media m
                JOIN item i ON i.id = m.item_id
                WHERE i.id IN (
                    SELECT si.item_id
                    FROM item_site si
                    WHERE si.site_id = ?
                    UNION
                    SELECT iis.item_id
                    FROM item_item_set iis
                    JOIN site_item_set sis ON sis.item_set_id = iis.item_set_id
                    WHERE sis.site_id = ?
                )
                AND m.has_original = 1
            ";

            $stmt = $connection->prepare($sql);
            $stmt->bindValue(1, $site->id(), \PDO::PARAM_INT);
            $stmt->bindValue(2, $site->id(), \PDO::PARAM_INT);
            $stmt->execute();

            $mediaCount = (int) $stmt->fetchColumn() ?: 0;
        } catch (\Exception $e) {
            $mediaCount = 'Unknown';
        }
        
        echo $view->partial(
            'common/site-quota-usage',
            [
                'site' => $site,
                'siteQuota' => $siteQuota,
                'currentUsage' => $currentUsage,
                'mediaCount' => $mediaCount,
            ]
        );
    }
    
    /**
     * Handle site quota form submission.
     * 
     * Processes the submitted site quota settings and saves them.
     * Only administrators can modify quota values.
     * 
     * @param Event $event The API event
     * @return void
     */
    public function handleSiteQuotaForm($event)
    {
        $response = $event->getParam('response');
        $site = $response->getContent();
        
        if (!$site) {
            return;
        }
        
        $request = $event->getParam('request');
        $data = $request->getContent();
        
        // Only admins are allowed to edit quotas
        $services = $this->getServiceLocator();
        $auth = $services->get('Omeka\AuthenticationService');
        $currentUser = $auth->getIdentity();
        
        // Verify if the user has admin role
        if (!$currentUser || $currentUser->getRole() !== 'global_admin') {
            return; // Block if not admin
        }
        
        // Log the data for debugging
        error_log('DiskQuota: Site form data: ' . print_r($data, true));
        
        // Check if the quota field was submitted - check both possible locations
        $siteQuota = null;
        if (isset($data['site_settings']['diskquota_site_quota'])) {
            $siteQuota = (int) $data['site_settings']['diskquota_site_quota'];
        } elseif (isset($data['o:settings']['diskquota_site_quota'])) {
            $siteQuota = (int) $data['o:settings']['diskquota_site_quota'];
        }
        
        // If we found a quota value, save it
        if ($siteQuota !== null) {
            // Validate quota (must be non-negative)
            if ($siteQuota < 0) {
                return;
            }
            
            // Save the site quota
            $siteSettings = $services->get('Omeka\Settings\Site');
            $siteSettings->setTargetId($site->getId());
            $siteSettings->set('diskquota_site_quota', $siteQuota);
            
            // Log the saved quota
            error_log('DiskQuota: Saved site quota: ' . $siteQuota . ' for site ID: ' . $site->getId());
        } else {
            error_log('DiskQuota: No quota value found in form data for site ID: ' . $site->getId());
        }
    }
    
    /**
     * Check if an upload would exceed the site's quota.
     * 
     * Examines file uploads to determine if they would exceed the site's disk quota.
     * If quota would be exceeded, adds an error message and prevents the upload.
     * 
     * @param Event $event The event that triggered this callback
     * @return void
     */
    public function checkSiteQuotaBeforeUpload($event)
    {
        $services = $this->getServiceLocator();
        $request = $event->getParam('request');
        
        // Skip if not a create operation
        if ($request->getOperation() !== 'create') {
            return;
        }
        
        // Initialize file size
        $fileSize = 0;
        
        // Try to get file size from HTTP request
        $httpRequest = $services->get('Request');
        if ($httpRequest instanceof \Laminas\Http\Request) {
            $files = $httpRequest->getFiles()->toArray();
            if (!empty($files)) {
                // Traverse the files array to find the first file
                foreach ($files as $fileData) {
                    if (is_array($fileData) && !empty($fileData['tmp_name']) &&
                            file_exists($fileData['tmp_name'])) {
                        $fileSize = filesize($fileData['tmp_name']);
                        break;
                    } elseif (is_array($fileData)) {
                        // Handle nested file arrays
                        foreach ($fileData as $nestedFile) {
                            if (is_array($nestedFile) && !empty($nestedFile['tmp_name']) &&
                                    file_exists($nestedFile['tmp_name'])) {
                                $fileSize = filesize($nestedFile['tmp_name']);
                                break 2;
                            }
                        }
                    }
                }
            }
        }
        
        // If we couldn't get file size from HTTP request, try to infer it from content data
        if ($fileSize <= 0) {
            $data = $request->getContent();
            
            // Check if there's a 'data' field with a size property (for OmekaS API uploads)
            if (!empty($data['data']) && !empty($data['data']['size'])) {
                $fileSize = (int)$data['data']['size'];
            } elseif (!empty($data['o:size'])) {
                // Check for o:size field, which might contain the file size for already processed uploads
                $fileSize = (int)$data['o:size'];
            }
        }
        
        // Skip if no file size could be determined
        if ($fileSize <= 0) {
            return;
        }
        
        // Try to determine which site this upload belongs to
        $siteId = null;
        
        // If this is a media being added to an item, get the item's site
        if (!empty($data['o:item']['o:id'])) {
            $itemId = $data['o:item']['o:id'];
            
            try {
                // Check if item is directly assigned to a site
                $connection = $services->get('Omeka\Connection');
                $stmt = $connection->prepare('SELECT site_id FROM item_site WHERE item_id = ? LIMIT 1');
                $stmt->bindValue(1, $itemId);
                $stmt->execute();
                $siteId = $stmt->fetchColumn();
                
                // If not found, check if item is in a site item set
                if (!$siteId) {
                    $stmt = $connection->prepare('
                        SELECT sis.site_id 
                        FROM site_item_set sis
                        JOIN item_item_set iis ON sis.item_set_id = iis.item_set_id
                        WHERE iis.item_id = ?
                        LIMIT 1
                    ');
                    $stmt->bindValue(1, $itemId);
                    $stmt->execute();
                    $siteId = $stmt->fetchColumn();
                }
            } catch (\Exception $e) {
                error_log('DiskQuota: Error determining site for item: ' . $e->getMessage());
            }
        }
        
        // Skip if we couldn't determine the site
        if (!$siteId) {
            return;
        }
        
        // Get the disk quota manager
        $diskQuotaManager = $services->get('DiskQuota\DiskQuotaManager');
        
        // Check if upload would exceed quota
        if ($diskQuotaManager->isSiteQuotaExceeded($siteId, $fileSize)) {
            // Get the site's quota and current usage for the error message
            $quota = $diskQuotaManager->getSiteQuota($siteId);
            $usedSpace = $diskQuotaManager->getUsedDiskSpaceBySite($siteId);
            
            // Format sizes for display
            $usedMB = round($usedSpace / (1024 * 1024), 2);
            $quotaMB = round($quota / (1024 * 1024), 2);
            $fileSizeMB = round($fileSize / (1024 * 1024), 2);
            
            // Log the error
            $msg = sprintf(
                'DiskQuota: Upload rejected for site %d. File size: %s MB, Used: %s MB, Limit: %s MB',
                $siteId,
                $fileSizeMB,
                $usedMB,
                $quotaMB
            );
            error_log($msg);
            
            // If quota exceeded, add error message and block upload
            $errorStore = $event->getParam('errorStore');
            if ($errorStore) {
                $errorStore->addError('file', new \Omeka\Stdlib\Message(
                    'Upload rejected: site quota exceeded. File: %s MB, Used: %s MB, Limit: %s MB',
                    $fileSizeMB,
                    $usedMB,
                    $quotaMB
                ));
            }
        }
    }
}

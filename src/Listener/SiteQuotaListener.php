<?php
declare(strict_types=1);

namespace DiskQuota\Listener;

use DiskQuota\Form\QuotaFieldset;
use Laminas\ServiceManager\ServiceLocatorInterface;

class SiteQuotaListener
{
    private $services;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
    }

    public function addSiteQuotaFieldset($event): void
    {
        $form = $event->getTarget();
        if (!$form instanceof \Omeka\Form\SiteForm) {
            return;
        }
        $siteId = $this->getCurrentSiteId();
        if (!$siteId) {
            return;
        }
        error_log('DiskQuota: Adding site quota fieldset for site ID: ' . $siteId);
        $site = $this->services->get('Omeka\EntityManager')->find('Omeka\Entity\Site', $siteId);
        $data = $this->getUsage($site, $siteId);
        $currentUser = $this->services->get('Omeka\AuthenticationService')->getIdentity();
        $isAdmin = $currentUser && $currentUser->getRole() === 'global_admin';
        if (!$form->has('site_settings')) {
            $form->add(['name' => 'site_settings', 'type' => 'fieldset']);
        }
        $this->addQuotaField($form->get('site_settings'), $data['siteQuota'], $isAdmin);
        if ($site && $data['siteQuota'] > 0) {
            $html = $this->services->get('ViewRenderer')->partial('common/site-quota-usage', $data);
            $targetId = $isAdmin ? 'diskquota_site_quota' : 'diskquota_site_quota_display';
            QuotaFieldset::appendUsage($html, $targetId);
        }
    }

    public function viewSiteQuotaDetails($event): void
    {
        $view = $event->getTarget();
        $site = $view->site;
        if (!$site) {
            return;
        }
        echo $view->partial('common/site-quota-usage', $this->getUsage($site, $site->id()));
    }

    private function getCurrentSiteId()
    {
        if (!$this->services->has('ControllerPluginManager')) {
            return null;
        }
        $plugins = $this->services->get('ControllerPluginManager');
        if (!$plugins->has('currentSite')) {
            return null;
        }
        $site = $plugins->get('currentSite')();
        return $site ? $site->id() : null;
    }

    private function getUsage($site, $siteId): array
    {
        $defaultQuota = $this->services->get('Omeka\Settings')->get('diskquota_default_site_quota', 1000);
        $data = ['site' => $site, 'siteQuota' => $defaultQuota, 'currentUsage' => 0, 'mediaCount' => 0];
        if ($site) {
            $settings = $this->services->get('Omeka\Settings\Site');
            $settings->setTargetId($siteId);
            $data['siteQuota'] = $settings->get('diskquota_site_quota', $defaultQuota);
            error_log('DiskQuota: Retrieved site quota for site ID ' . $siteId . ': ' . $data['siteQuota']);
            $manager = $this->services->get('DiskQuota\DiskQuotaManager');
            $data['currentUsage'] = $manager->getUsedDiskSpaceBySite($siteId);
            $data['mediaCount'] = $this->getMediaCount($siteId);
        }
        return $data;
    }

    private function getMediaCount($siteId)
    {
        try {
            $connection = $this->services->get('Omeka\Connection');
            $stmt = $connection->prepare('
                SELECT COUNT(m.id)
                FROM media m
                JOIN item_site si ON si.item_id = m.item_id
                WHERE si.site_id = ? AND m.has_original = 1
            ');
            $stmt->bindValue(1, $siteId, \PDO::PARAM_INT);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    private function addQuotaField($fieldset, $quota, bool $isAdmin): void
    {
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
                    'value' => $quota,
                    'required' => false,
                ],
            ]);

            // Force the value to be set correctly
            $fieldset->get('diskquota_site_quota')->setValue($quota);
            error_log('DiskQuota: Set form field value to ' . $quota);
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
                    'value' => $quota . ' MB', // Display with unit
                ],
            ]);
        }
    }

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
        $services = $this->services;
        $auth = $services->get('Omeka\AuthenticationService');
        $currentUser = $auth->getIdentity();

        // Verify if the user has admin role
        if (!$currentUser || $currentUser->getRole() !== 'global_admin') {
            return; // Block if not admin
        }

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
}

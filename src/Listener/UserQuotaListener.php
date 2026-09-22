<?php
declare(strict_types=1);

namespace DiskQuota\Listener;

use DiskQuota\Form\QuotaFieldset;
use Laminas\ServiceManager\ServiceLocatorInterface;

class UserQuotaListener
{
    private $services;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
    }

    public function addUserQuotaFieldset($event): void
    {
        $form = $event->getTarget();
        if (!$form instanceof \Omeka\Form\UserForm) {
            return;
        }
        $options = $form->getOptions();
        $userId = isset($options['user_id']) ? (int) $options['user_id'] : null;
        $entityManager = $this->services->get('Omeka\EntityManager');
        $user = $userId ? $entityManager->find('Omeka\Entity\User', $userId) : null;
        $data = $this->getUsage($user, $userId);
        $currentUser = $this->services->get('Omeka\AuthenticationService')->getIdentity();
        $isAdmin = $currentUser && $currentUser->getRole() === 'global_admin';
        $this->addQuotaField($form->get('user-settings'), $data['userQuota'], $isAdmin);
        if ($user && $data['userQuota'] > 0) {
            $html = $this->services->get('ViewRenderer')->partial('common/user-quota-usage', $data);
            $targetId = $isAdmin ? 'diskquota_user_quota' : 'diskquota_user_quota_display';
            QuotaFieldset::appendUsage($html, $targetId, true);
        }
    }

    public function viewUserQuotaDetails($event): void
    {
        $view = $event->getTarget();
        $user = $view->resource;
        $data = $this->getUsage($user, $user->id());
        if ($data['mediaCount'] === 'Unknown') {
            $data['mediaCount'] = $view->translate('Unknown');
        }
        echo $view->partial('common/user-quota-usage', $data);
    }

    private function getUsage($user, $userId): array
    {
        $defaultQuota = $this->services->get('Omeka\Settings')->get('diskquota_default_user_quota', 500);
        $data = ['user' => $user, 'userQuota' => $defaultQuota, 'currentUsage' => 0, 'mediaCount' => 0];
        if ($user) {
            $settings = $this->services->get('Omeka\Settings\User');
            $settings->setTargetId($userId);
            $data['userQuota'] = $settings->get('diskquota_user_quota', $defaultQuota);
            $manager = $this->services->get('DiskQuota\DiskQuotaManager');
            $data['currentUsage'] = $manager->getUsedDiskSpaceByUser($userId);
            $data['mediaCount'] = $this->getMediaCount($userId);
        }
        return $data;
    }

    private function getMediaCount($userId)
    {
        try {
            $response = $this->services->get('Omeka\ApiManager')->search('media', ['owner_id' => $userId]);
            return $response->getTotalResults();
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    private function addQuotaField($fieldset, $quota, bool $isAdmin): void
    {
        if ($isAdmin) {
            // Add editable number input for admins
            $fieldset->add([
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
                'value' => $quota,
                'required' => false,
            ],
            ]);
        } else {
         // Add read-only text display for non-admins
            $fieldset->add([
            'name' => 'diskquota_user_quota_display',
            'type' => 'Text',
            'options' => [
                'label' => 'User Quota (MB)', // @translate
                'info' => 'Disk quota allocated to this user.', // @translate
            ],
            'attributes' => [
                'id' => 'diskquota_user_quota_display',
                'readonly' => true,
                'value' => $quota . ' MB', // Display with unit
            ],
            ]);
        }
    }

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
        $services = $this->services;
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
            $services = $this->services;
            $settings = $services->get('Omeka\Settings\User');
            $settings->setTargetId($user->getId());
            $settings->set('diskquota_user_quota', $userQuota);
        }
    }
}

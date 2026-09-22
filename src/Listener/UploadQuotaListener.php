<?php
declare(strict_types=1);

namespace DiskQuota\Listener;

use DiskQuota\Service\UploadSizeResolver;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Stdlib\Message;

class UploadQuotaListener
{
    private $services;

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
    }

    public function checkUserQuotaBeforeUpload($event): void
    {
        $user = $this->services->get('Omeka\AuthenticationService')->getIdentity();
        if (!$user) {
            return;
        }
        $request = $event->getParam('request');
        if ($request->getOperation() !== 'create') {
            return;
        }
        $size = $this->getFileSize($request->getContent());
        $userId = $user->getId();
        error_log('DiskQuota: Checking file upload with size: ' . $size . ' bytes for user ID: ' . $userId);
        if ($size <= 0) {
            return;
        }
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        if ($manager->isQuotaExceeded($userId, $size)) {
            $this->rejectUserUpload($event, $userId, $size);
        }
    }

    public function checkSiteQuotaBeforeUpload($event): void
    {
        $request = $event->getParam('request');
        if ($request->getOperation() !== 'create') {
            return;
        }
        $data = $request->getContent();
        $size = $this->getFileSize($data);
        if ($size <= 0 || empty($data['o:item']['o:id'])) {
            return;
        }
        $siteIds = $this->getSiteIds($data['o:item']['o:id']);
        if (!$siteIds) {
            return;
        }
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        foreach ($siteIds as $siteId) {
            if ($manager->isSiteQuotaExceeded($siteId, $size)) {
                $this->rejectSiteUpload($event, $siteId, $size);
            }
        }
    }

    private function getFileSize(array $data): int
    {
        return (new UploadSizeResolver())->getSize($this->services->get('Request'), $data);
    }

    private function getSiteIds($itemId): array
    {
        $siteIds = [];
        try {
            $connection = $this->services->get('Omeka\Connection');
            $stmt = $connection->prepare('SELECT site_id FROM item_site WHERE item_id = ?');
            $stmt->bindValue(1, $itemId);
            $stmt->execute();
            while (($siteId = $stmt->fetchColumn()) !== false) {
                $siteIds[] = (int) $siteId;
            }
        } catch (\Exception $e) {
            error_log('DiskQuota: Error determining site for item: ' . $e->getMessage());
        }
        return $siteIds;
    }

    private function rejectUserUpload($event, $userId, int $size): void
    {
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        $quotaMB = round($manager->getUserQuota($userId) / (1024 * 1024), 2);
        $usedMB = round($manager->getUsedDiskSpaceByUser($userId) / (1024 * 1024), 2);
        $sizeMB = round($size / (1024 * 1024), 2);
        error_log(sprintf(
            'DiskQuota: Upload rejected. File size: %s MB, Used: %s MB, Limit: %s MB',
            $sizeMB,
            $usedMB,
            $quotaMB
        ));
        $errors = $event->getParam('errorStore');
        if ($errors) {
            $errors->addError('file', new Message(
                'Upload rejected: quota exceeded. File: %s MB, Used: %s MB, Limit: %s MB',
                $sizeMB,
                $usedMB,
                $quotaMB
            ));
        }
    }

    private function rejectSiteUpload($event, int $siteId, int $size): void
    {
        $manager = $this->services->get('DiskQuota\DiskQuotaManager');
        $quotaMB = round($manager->getSiteQuota($siteId) / (1024 * 1024), 2);
        $usedMB = round($manager->getUsedDiskSpaceBySite($siteId) / (1024 * 1024), 2);
        $sizeMB = round($size / (1024 * 1024), 2);
        error_log(sprintf(
            'DiskQuota: Upload rejected for site %d. File size: %s MB, Used: %s MB, Limit: %s MB',
            $siteId,
            $sizeMB,
            $usedMB,
            $quotaMB
        ));
        $errors = $event->getParam('errorStore');
        if ($errors) {
            $errors->addError('file', new Message(
                'Upload rejected: site quota exceeded. File: %s MB, Used: %s MB, Limit: %s MB',
                $sizeMB,
                $usedMB,
                $quotaMB
            ));
        }
    }
}

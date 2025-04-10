<?php
namespace DiskQuota\Service;

use Laminas\ServiceManager\ServiceLocatorInterface;

class DiskQuotaManager
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $services;
    
    /**
     * @param ServiceLocatorInterface $services
     */
    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
    }
    
    /**
     * Get the total disk space used by a user in bytes
     *
     * @param int $userId
     * @return int Total size in bytes
     */
    public function getUsedDiskSpaceByUser($userId)
    {
        $connection = $this->services->get('Omeka\Connection');
        
        try {
            // Query to get total size of all media files owned by this user
            $stmt = $connection->prepare('
                SELECT COALESCE(SUM(m.size), 0) AS total_size
                FROM media m
                JOIN resource r ON m.id = r.id
                WHERE r.owner_id = ? AND m.has_original = 1
            ');
            $stmt->bindValue(1, $userId);
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (\Exception $e) {
            // Log the error
            error_log('DiskQuota: Error calculating user disk usage: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get the total disk space used by a site in bytes
     *
     * @param int $siteId
     * @return int Total size in bytes
     */
    public function getUsedDiskSpaceBySite($siteId)
    {
        $connection = $this->services->get('Omeka\Connection');
        
        try {
            // Query to get all media files associated with a site through item_site table
            $sql = "
                SELECT COALESCE(SUM(m.size), 0) AS total_size
                FROM media m
                JOIN item i ON m.item_id = i.id
                JOIN item_site si ON si.item_id = i.id
                WHERE si.site_id = ? AND m.has_original = 1
            ";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(1, $siteId);
            $stmt->execute();
            $sizeFromDirectItems = (int) $stmt->fetchColumn() ?: 0;
            
            // Also get media files associated with a site through item sets
            $sql = "
                SELECT COALESCE(SUM(m.size), 0) AS total_size
                FROM media m
                JOIN item i ON m.item_id = i.id
                JOIN item_item_set iis ON iis.item_id = i.id
                JOIN site_item_set sis ON sis.item_set_id = iis.item_set_id
                WHERE sis.site_id = ? AND m.has_original = 1
            ";
            $stmt = $connection->prepare($sql);
            $stmt->bindValue(1, $siteId);
            $stmt->execute();
            $sizeFromItemSets = (int) $stmt->fetchColumn() ?: 0;
            
            // Return the total size
            return $sizeFromDirectItems + $sizeFromItemSets;
        } catch (\Exception $e) {
            // Log the error
            error_log('DiskQuota: Error calculating site disk usage: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get the quota for a user in bytes
     *
     * @param int $userId
     * @return int Quota in bytes (0 means unlimited)
     */
    public function getUserQuota($userId)
    {
        $settings = $this->services->get('Omeka\Settings\User');
        $settings->setTargetId($userId);
        
        // Get default user quota from global settings
        $globalSettings = $this->services->get('Omeka\Settings');
        $defaultQuota = $globalSettings->get('diskquota_default_user_quota', 500); // Default 500MB
        
        // Get user's quota or use default
        $userQuota = $settings->get('diskquota_user_quota', $defaultQuota);
        
        // Convert MB to bytes
        return $userQuota * 1024 * 1024;
    }
    
    /**
     * Get the quota for a site in bytes
     *
     * @param int $siteId
     * @return int Quota in bytes (0 means unlimited)
     */
    public function getSiteQuota($siteId)
    {
        // Get default site quota from global settings
        $globalSettings = $this->services->get('Omeka\Settings');
        $defaultQuota = $globalSettings->get('diskquota_default_site_quota', 1000); // Default 1GB
        
        // Get site's quota or use default
        $siteSettings = $this->services->get('Omeka\Settings\Site');
        $siteSettings->setTargetId($siteId);
        $siteQuota = $siteSettings->get('diskquota_site_quota', $defaultQuota);
        
        error_log("DiskQuota: site $siteId quota $siteQuota MB (default $defaultQuota MB)");
        
        // Convert MB to bytes
        return $siteQuota * 1024 * 1024;
    }
    
    /**
     * Check if a user has exceeded their quota
     *
     * @param int $userId
     * @param int $additionalBytes Additional bytes to check (for uploads)
     * @return bool True if quota exceeded
     */
    public function isQuotaExceeded($userId, $additionalBytes = 0)
    {
        $quota = $this->getUserQuota($userId);
        
        // Unlimited quota
        if ($quota <= 0) {
            return false;
        }
        
        $usedSpace = $this->getUsedDiskSpaceByUser($userId);
        
        return ($usedSpace + $additionalBytes) > $quota;
    }
    
    /**
     * Check if a site has exceeded its quota
     *
     * @param int $siteId
     * @param int $additionalBytes Additional bytes to check (for uploads)
     * @return bool True if quota exceeded
     */
    public function isSiteQuotaExceeded($siteId, $additionalBytes = 0)
    {
        $quota = $this->getSiteQuota($siteId);
        
        // Unlimited quota
        if ($quota <= 0) {
            return false;
        }
        
        $usedSpace = $this->getUsedDiskSpaceBySite($siteId);
        
        return ($usedSpace + $additionalBytes) > $quota;
    }
}

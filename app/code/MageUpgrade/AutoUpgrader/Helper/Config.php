<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    private const XML_PATH_ENABLED = 'mageupgrade_autoupgrader/general/enabled';
    private const XML_PATH_AUTO_BACKUP = 'mageupgrade_autoupgrader/general/auto_backup';
    private const XML_PATH_MAINTENANCE_MODE = 'mageupgrade_autoupgrader/general/maintenance_mode';
    private const XML_PATH_AUTO_FIX = 'mageupgrade_autoupgrader/general/auto_fix_enabled';
    private const XML_PATH_BACKUP_DB = 'mageupgrade_autoupgrader/upgrade/backup_database';
    private const XML_PATH_COMPILE = 'mageupgrade_autoupgrader/upgrade/compile_after_upgrade';
    private const XML_PATH_STATIC_DEPLOY = 'mageupgrade_autoupgrader/upgrade/deploy_static_after_upgrade';

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function isAutoBackupEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_AUTO_BACKUP, ScopeInterface::SCOPE_STORE);
    }

    public function isMaintenanceModeEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_MAINTENANCE_MODE, ScopeInterface::SCOPE_STORE);
    }

    public function isAutoFixEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_AUTO_FIX, ScopeInterface::SCOPE_STORE);
    }

    public function shouldBackupDatabase(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_BACKUP_DB, ScopeInterface::SCOPE_STORE);
    }

    public function shouldCompileAfterUpgrade(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_COMPILE, ScopeInterface::SCOPE_STORE);
    }

    public function shouldDeployStaticAfterUpgrade(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_STATIC_DEPLOY, ScopeInterface::SCOPE_STORE);
    }
}

<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use MageUpgrade\AutoUpgrader\Api\VersionResolverInterface;
use MageUpgrade\AutoUpgrader\Api\ExtensionManagerInterface;

class Dashboard extends Template
{
    public function __construct(
        Context $context,
        private readonly VersionResolverInterface $versionResolver,
        private readonly ExtensionManagerInterface $extensionManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getCurrentVersion(): string
    {
        return $this->versionResolver->getCurrentVersion();
    }

    public function getAvailableVersions(): array
    {
        return $this->versionResolver->getAvailableVersions();
    }

    public function getInstalledExtensions(): array
    {
        return $this->extensionManager->getInstalledExtensions();
    }

    public function getStartUpgradeUrl(): string
    {
        return $this->getUrl('autoupgrader/upgrade/start');
    }

    public function getConfirmUpgradeUrl(): string
    {
        return $this->getUrl('autoupgrader/upgrade/confirm');
    }

    public function getProgressUrl(): string
    {
        return $this->getUrl('autoupgrader/upgrade/progress');
    }

    public function getScanUrl(): string
    {
        return $this->getUrl('autoupgrader/scan/run');
    }

    public function getSystemCheckUrl(): string
    {
        return $this->getUrl('autoupgrader/scan/systemCheck');
    }
}

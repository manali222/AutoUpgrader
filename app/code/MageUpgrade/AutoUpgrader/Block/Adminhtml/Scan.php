<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use MageUpgrade\AutoUpgrader\Api\VersionResolverInterface;

class Scan extends Template
{
    public function __construct(
        Context $context,
        private readonly VersionResolverInterface $versionResolver,
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

    public function getRunScanUrl(): string
    {
        return $this->getUrl('autoupgrader/scan/run');
    }
}

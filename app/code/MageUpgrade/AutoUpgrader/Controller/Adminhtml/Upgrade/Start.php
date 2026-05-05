<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Controller\Adminhtml\Upgrade;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MageUpgrade\AutoUpgrader\Api\UpgradeManagerInterface;

class Start extends Action
{
    public const ADMIN_RESOURCE = 'MageUpgrade_AutoUpgrader::upgrade';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly UpgradeManagerInterface $upgradeManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $targetVersion = $this->getRequest()->getParam('target_version');
            $includePatches = (bool) $this->getRequest()->getParam('include_patches', true);

            if (empty($targetVersion)) {
                return $result->setData(['success' => false, 'message' => 'Target version is required']);
            }

            $upgradeLog = $this->upgradeManager->startUpgrade($targetVersion, $includePatches);

            return $result->setData([
                'success' => true,
                'upgrade_id' => $upgradeLog->getUpgradeId(),
                'status' => $upgradeLog->getStatus(),
                'message' => 'Scan completed. Please review and confirm to proceed.',
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

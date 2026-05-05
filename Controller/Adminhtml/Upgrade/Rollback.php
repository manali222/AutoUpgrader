<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Controller\Adminhtml\Upgrade;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MageUpgrade\AutoUpgrader\Api\UpgradeManagerInterface;

class Rollback extends Action
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
            $upgradeId = (int) $this->getRequest()->getParam('upgrade_id');

            if (!$upgradeId) {
                return $result->setData(['success' => false, 'message' => 'Upgrade ID is required']);
            }

            $success = $this->upgradeManager->rollback($upgradeId);

            return $result->setData([
                'success' => $success,
                'message' => $success ? 'Rollback completed successfully.' : 'Rollback failed.',
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

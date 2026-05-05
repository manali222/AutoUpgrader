<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Controller\Adminhtml\Upgrade;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MageUpgrade\AutoUpgrader\Api\ProgressTrackerInterface;

class Progress extends Action
{
    public const ADMIN_RESOURCE = 'MageUpgrade_AutoUpgrader::dashboard';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ProgressTrackerInterface $progressTracker
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $upgradeId = (int) $this->getRequest()->getParam('upgrade_id');

        if (!$upgradeId) {
            return $result->setData(['success' => false, 'message' => 'Upgrade ID is required']);
        }

        $progress = $this->progressTracker->getProgress($upgradeId);
        return $result->setData(['success' => true, 'data' => $progress]);
    }
}

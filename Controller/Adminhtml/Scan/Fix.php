<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Controller\Adminhtml\Scan;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use MageUpgrade\AutoUpgrader\Api\AutoFixerInterface;

class Fix extends Action
{
    public const ADMIN_RESOURCE = 'MageUpgrade_AutoUpgrader::upgrade';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly AutoFixerInterface $autoFixer
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $scanId = (int) $this->getRequest()->getParam('scan_id');

            if (!$scanId) {
                return $result->setData(['success' => false, 'message' => 'Scan ID is required']);
            }

            $fixResults = $this->autoFixer->applyFixes($scanId);

            return $result->setData([
                'success' => true,
                'data' => $fixResults,
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

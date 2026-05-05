<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Controller\Adminhtml\Scan;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use MageUpgrade\AutoUpgrader\Api\CompatibilityScannerInterface;
use MageUpgrade\AutoUpgrader\Api\ExtensionManagerInterface;

class Run extends Action
{
    public const ADMIN_RESOURCE = 'MageUpgrade_AutoUpgrader::scan';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly CompatibilityScannerInterface $scanner,
        private readonly ExtensionManagerInterface $extensionManager,
        private readonly Json $json
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $targetVersion = $this->getRequest()->getParam('target_version');

            if (empty($targetVersion)) {
                return $result->setData(['success' => false, 'message' => 'Target version is required']);
            }

            // Run scan
            $scanResult = $this->scanner->runScan($targetVersion);

            // Get extension compatibility
            $extensions = $this->extensionManager->findCompatibleVersions($targetVersion);

            $issues = $this->json->unserialize($scanResult->getIssuesJson() ?? '[]');
            $impactedFiles = $this->json->unserialize($scanResult->getImpactedFilesJson() ?? '[]');

            return $result->setData([
                'success' => true,
                'data' => [
                    'scan_id' => $scanResult->getScanId(),
                    'total_issues' => $scanResult->getTotalIssues(),
                    'critical_issues' => $scanResult->getCriticalIssues(),
                    'warnings' => $scanResult->getWarnings(),
                    'auto_fixable' => $scanResult->getAutoFixable(),
                    'issues' => $issues,
                    'impacted_files' => $impactedFiles,
                    'extensions' => $extensions,
                ],
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

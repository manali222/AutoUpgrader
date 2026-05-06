<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Controller\Adminhtml\Upgrade;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use MageUpgrade\AutoUpgrader\Api\Data\UpgradeLogInterface;
use MageUpgrade\AutoUpgrader\Api\ProgressTrackerInterface;
use MageUpgrade\AutoUpgrader\Api\VersionResolverInterface;
use MageUpgrade\AutoUpgrader\Model\UpgradeLogFactory;
use MageUpgrade\AutoUpgrader\Model\ResourceModel\UpgradeLog as UpgradeLogResource;

class Prepare extends Action
{
    public const ADMIN_RESOURCE = 'MageUpgrade_AutoUpgrader::upgrade';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly UpgradeLogFactory $upgradeLogFactory,
        private readonly UpgradeLogResource $upgradeLogResource,
        private readonly VersionResolverInterface $versionResolver,
        private readonly ProgressTrackerInterface $progressTracker,
        private readonly Json $json
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $targetVersion = $this->getRequest()->getParam('target_version');
        $scanId = (int) $this->getRequest()->getParam('scan_id', 0);

        if (empty($targetVersion)) {
            return $result->setData(['success' => false, 'message' => 'Target version is required']);
        }

        try {
            $currentVersion = $this->versionResolver->getCurrentVersion();

            $log = $this->upgradeLogFactory->create();
            $log->setFromVersion($currentVersion);
            $log->setToVersion($targetVersion);
            $log->setStatus(UpgradeLogInterface::STATUS_BACKING_UP);
            $log->setProgressPercent(0);
            $log->setCurrentStep('Preparing...');
            $log->setScanId($scanId ?: null);
            $log->setInitiatedBy('admin');
            $log->setStartedAt(date('Y-m-d H:i:s'));
            $log->setStepsLog($this->json->serialize([]));
            $this->upgradeLogResource->save($log);

            $upgradeId = (int) $log->getUpgradeId();

            // Generate token and init progress file for pub/autoupgrader_status.php polling
            $token = bin2hex(random_bytes(32));
            $this->progressTracker->initProgressFile($upgradeId, $token);

            return $result->setData([
                'success' => true,
                'upgrade_id' => $upgradeId,
                'status_token' => $token,
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

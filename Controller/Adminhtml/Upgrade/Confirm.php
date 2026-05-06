<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Controller\Adminhtml\Upgrade;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use MageUpgrade\AutoUpgrader\Service\ProgressTracker;
use MageUpgrade\AutoUpgrader\Model\UpgradeLogFactory;
use MageUpgrade\AutoUpgrader\Model\ResourceModel\UpgradeLog as UpgradeLogResource;

class Confirm extends Action
{
    public const ADMIN_RESOURCE = 'MageUpgrade_AutoUpgrader::upgrade';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ProgressTracker $progressTracker,
        private readonly UpgradeLogFactory $upgradeLogFactory,
        private readonly UpgradeLogResource $upgradeLogResource,
        private readonly DirectoryList $directoryList
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

            // Load upgrade log to get version info
            $upgradeLog = $this->upgradeLogFactory->create();
            $this->upgradeLogResource->load($upgradeLog, $upgradeId);

            if (!$upgradeLog->getUpgradeId()) {
                return $result->setData(['success' => false, 'message' => 'Upgrade not found']);
            }

            // Generate a secure token for the standalone status endpoint
            $token = bin2hex(random_bytes(32));

            // Initialize the progress file
            $this->progressTracker->initFileProgress(
                $upgradeId,
                $token,
                $upgradeLog->getFromVersion(),
                $upgradeLog->getToVersion()
            );

            // Spawn background CLI process
            $phpBinary = PHP_BINARY;
            $binMagento = $this->directoryList->getRoot() . '/bin/magento';
            $targetVersion = $upgradeLog->getToVersion();

            $command = sprintf(
                'nohup %s %s autoupgrader:upgrade %s --yes --upgrade-id=%d --token=%s > %s/autoupgrader_upgrade.log 2>&1 &',
                escapeshellarg($phpBinary),
                escapeshellarg($binMagento),
                escapeshellarg($targetVersion),
                $upgradeId,
                escapeshellarg($token),
                $this->directoryList->getPath(DirectoryList::VAR_DIR)
            );

            exec($command);

            // Build the status URL for the frontend
            $statusUrl = '/autoupgrader_status.php?token=' . urlencode($token);

            return $result->setData([
                'success' => true,
                'upgrade_id' => $upgradeId,
                'status' => 'in_progress',
                'status_url' => $statusUrl,
                'message' => 'Upgrade started in background.',
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

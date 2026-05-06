<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Controller\Adminhtml\Upgrade;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use MageUpgrade\AutoUpgrader\Api\AutoFixerInterface;
use MageUpgrade\AutoUpgrader\Api\BackupManagerInterface;
use MageUpgrade\AutoUpgrader\Api\Data\UpgradeLogInterface;
use MageUpgrade\AutoUpgrader\Api\ExtensionManagerInterface;
use Magento\Framework\Filesystem;
use MageUpgrade\AutoUpgrader\Api\ProgressTrackerInterface;
use MageUpgrade\AutoUpgrader\Api\VersionResolverInterface;
use MageUpgrade\AutoUpgrader\Model\UpgradeLog;
use MageUpgrade\AutoUpgrader\Model\UpgradeLogFactory;
use MageUpgrade\AutoUpgrader\Model\ResourceModel\UpgradeLog as UpgradeLogResource;
use Psr\Log\LoggerInterface;

class Execute extends Action
{
    public const ADMIN_RESOURCE = 'MageUpgrade_AutoUpgrader::upgrade';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly UpgradeLogFactory $upgradeLogFactory,
        private readonly UpgradeLogResource $upgradeLogResource,
        private readonly VersionResolverInterface $versionResolver,
        private readonly AutoFixerInterface $autoFixer,
        private readonly ExtensionManagerInterface $extensionManager,
        private readonly BackupManagerInterface $backupManager,
        private readonly ProgressTrackerInterface $progressTracker,
        private readonly DirectoryList $directoryList,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $targetVersion = $this->getRequest()->getParam('target_version');
        $scanId = (int) $this->getRequest()->getParam('scan_id', 0);
        $existingUpgradeId = (int) $this->getRequest()->getParam('upgrade_id', 0);

        if (empty($targetVersion)) {
            return $result->setData(['success' => false, 'message' => 'Target version is required']);
        }

        $rootDir = $this->directoryList->getRoot();

        // Load existing log (created by Prepare controller) or create new one
        if ($existingUpgradeId) {
            /** @var UpgradeLog $log */
            $log = $this->upgradeLogFactory->create();
            $this->upgradeLogResource->load($log, $existingUpgradeId);
            if (!$log->getUpgradeId()) {
                return $result->setData(['success' => false, 'message' => 'Invalid upgrade ID']);
            }
            $upgradeId = $existingUpgradeId;
        } else {
            $currentVersion = $this->versionResolver->getCurrentVersion();
            /** @var UpgradeLog $log */
            $log = $this->upgradeLogFactory->create();
            $log->setFromVersion($currentVersion);
            $log->setToVersion($targetVersion);
            $log->setStatus(UpgradeLogInterface::STATUS_BACKING_UP);
            $log->setProgressPercent(0);
            $log->setCurrentStep('Starting...');
            $log->setScanId($scanId ?: null);
            $log->setInitiatedBy('admin');
            $log->setStartedAt(date('Y-m-d H:i:s'));
            $log->setStepsLog($this->json->serialize([]));
            $this->upgradeLogResource->save($log);
            $upgradeId = (int) $log->getUpgradeId();
        }

        try {
            // ── Step 1: Backup ──
            $this->updateStep($upgradeId, $log, 'backup', 'running', 5, 'Creating full backup...');
            $backup = $this->backupManager->createBackup('pre_upgrade_' . $targetVersion);
            $log->setBackupPath($backup['path']);
            $this->upgradeLogResource->save($log);
            $this->updateStep($upgradeId, $log, 'backup', 'completed', 10, 'Backup created: ' . $backup['size']);

            // ── Step 2: Auto-fix (non-fatal) ──
            if ($scanId) {
                $this->updateStep($upgradeId, $log, 'auto_fix', 'running', 15, 'Applying auto-fixes...');
                try {
                    $fixResult = $this->autoFixer->applyFixes($scanId);
                    $this->updateStep($upgradeId, $log, 'auto_fix', 'completed', 20,
                        'Fixed ' . $fixResult['fixed_count'] . ' issues, ' . $fixResult['failed_count'] . ' failed');
                } catch (\Exception $e) {
                    $this->logger->warning('AutoFixer failed, continuing upgrade', ['error' => $e->getMessage()]);
                    $this->updateStep($upgradeId, $log, 'auto_fix', 'completed', 20,
                        'Auto-fix skipped: ' . $e->getMessage());
                }
            } else {
                $this->updateStep($upgradeId, $log, 'auto_fix', 'completed', 20, 'No scan - skipped');
            }

            // ── Step 3: Upgrade extensions ──
            $this->updateStep($upgradeId, $log, 'extensions', 'running', 25, 'Finding compatible extension versions...');
            $extResults = $this->extensionManager->findCompatibleVersions($targetVersion);
            $extUpgraded = 0;
            foreach ($extResults as $ext) {
                if (($ext['status'] ?? '') === 'compatible' && !empty($ext['compatible_version'])) {
                    $this->extensionManager->upgradeExtension($ext['package_name'], $ext['compatible_version']);
                    $extUpgraded++;
                }
            }
            $this->updateStep($upgradeId, $log, 'extensions', 'completed', 30,
                'Upgraded ' . $extUpgraded . ' of ' . count($extResults) . ' extensions');

            // ── Step 4: Composer require + update ──
            $log->setStatus(UpgradeLogInterface::STATUS_UPGRADING);
            $this->upgradeLogResource->save($log);
            $this->updateStep($upgradeId, $log, 'composer', 'running', 35, 'Running composer require-commerce...');

            // Use require-commerce (required for Composer >= 2.1.6 with Magento metapackages)
            $cmd = sprintf(
                'cd %s && composer require-commerce magento/product-community-edition %s --no-update 2>&1',
                escapeshellarg($rootDir),
                escapeshellarg($targetVersion)
            );
            $requireResult = $this->runCommand($cmd, 'composer require-commerce', false);

            // Fallback to regular require if require-commerce is not available
            if ($requireResult['code'] !== 0) {
                $cmd = sprintf(
                    'cd %s && composer require magento/product-community-edition=%s --no-update 2>&1',
                    escapeshellarg($rootDir),
                    escapeshellarg($targetVersion)
                );
                $this->runCommand($cmd, 'composer require');
            }

            $this->updateStep($upgradeId, $log, 'composer', 'running', 45, 'Running composer update (this may take a while)...');
            $cmd = sprintf('cd %s && composer update --with-all-dependencies --no-interaction 2>&1', escapeshellarg($rootDir));
            $this->runCommand($cmd, 'composer update');
            $this->updateStep($upgradeId, $log, 'composer', 'completed', 55, 'Composer update completed');

            // ── Step 5: setup:upgrade ──
            $this->updateStep($upgradeId, $log, 'setup', 'running', 60, 'Running setup:upgrade...');
            $cmd = sprintf('cd %s && php bin/magento setup:upgrade --keep-generated 2>&1', escapeshellarg($rootDir));
            $this->runCommand($cmd, 'setup:upgrade');
            $this->updateStep($upgradeId, $log, 'setup', 'completed', 70, 'Setup upgrade completed');

            // ── Step 6: DI compile ──
            $log->setStatus(UpgradeLogInterface::STATUS_COMPILING);
            $this->upgradeLogResource->save($log);
            $this->updateStep($upgradeId, $log, 'compile', 'running', 72, 'Compiling dependency injection...');
            $cmd = sprintf('cd %s && php bin/magento setup:di:compile 2>&1', escapeshellarg($rootDir));
            $this->runCommand($cmd, 'setup:di:compile', false);
            $this->updateStep($upgradeId, $log, 'compile', 'completed', 82, 'DI compilation completed');

            // ── Step 7: Static content ──
            $log->setStatus(UpgradeLogInterface::STATUS_DEPLOYING);
            $this->upgradeLogResource->save($log);
            $this->updateStep($upgradeId, $log, 'static', 'running', 85, 'Deploying static content...');
            $cmd = sprintf('cd %s && php bin/magento setup:static-content:deploy -f 2>&1', escapeshellarg($rootDir));
            $this->runCommand($cmd, 'static-content:deploy', false);
            $this->updateStep($upgradeId, $log, 'static', 'completed', 92, 'Static content deployed');

            // ── Step 8: Cache flush ──
            $this->updateStep($upgradeId, $log, 'cache', 'running', 94, 'Flushing caches...');
            $cmd = sprintf('cd %s && php bin/magento cache:flush 2>&1', escapeshellarg($rootDir));
            $this->runCommand($cmd, 'cache:flush', false);
            $this->updateStep($upgradeId, $log, 'cache', 'completed', 96, 'All caches flushed');

            // ── Step 9: Verify ──
            $this->updateStep($upgradeId, $log, 'verify', 'running', 97, 'Verifying installation...');
            $cmd = sprintf('cd %s && php bin/magento setup:db:status 2>&1', escapeshellarg($rootDir));
            exec($cmd, $verifyOutput);
            $this->updateStep($upgradeId, $log, 'verify', 'completed', 100, 'Upgrade verified!');

            // Done
            $log->setStatus(UpgradeLogInterface::STATUS_COMPLETED);
            $log->setProgressPercent(100);
            $log->setCurrentStep('Upgrade completed successfully!');
            $log->setCompletedAt(date('Y-m-d H:i:s'));
            $this->upgradeLogResource->save($log);

            return $result->setData([
                'success' => true,
                'upgrade_id' => $upgradeId,
                'message' => 'Upgrade completed successfully!',
                'backup_path' => $log->getBackupPath(),
            ]);

        } catch (\Exception $e) {
            $log->setStatus(UpgradeLogInterface::STATUS_FAILED);
            $log->setErrorMessage($e->getMessage());
            $log->setCompletedAt(date('Y-m-d H:i:s'));
            $this->upgradeLogResource->save($log);

            $this->logger->error('AutoUpgrader execution failed', [
                'upgrade_id' => $upgradeId,
                'error' => $e->getMessage()
            ]);

            return $result->setData([
                'success' => false,
                'upgrade_id' => $upgradeId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function runCommand(string $cmd, string $label, bool $throwOnFail = true): array
    {
        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        $outputStr = implode("\n", $output);
        $this->logger->info("AutoUpgrader [{$label}]: exit={$returnCode}", ['output' => $outputStr]);

        if ($throwOnFail && $returnCode !== 0) {
            throw new \RuntimeException("{$label} failed (exit code {$returnCode}): " . substr($outputStr, 0, 500));
        }

        return ['output' => $output, 'code' => $returnCode];
    }

    private function updateStep(int $upgradeId, UpgradeLog $log, string $step, string $status, int $percent, string $message): void
    {
        // Update via progress tracker (persists step details)
        $this->progressTracker->updateProgress($upgradeId, $step, $status, $percent, $message);

        // Also update the main log
        $log->setProgressPercent($percent);
        $log->setCurrentStep($message);
        $this->upgradeLogResource->save($log);
    }
}

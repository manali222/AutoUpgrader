<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Service;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\Serialize\Serializer\Json;
use MageUpgrade\AutoUpgrader\Api\AutoFixerInterface;
use MageUpgrade\AutoUpgrader\Api\BackupManagerInterface;
use MageUpgrade\AutoUpgrader\Api\CompatibilityScannerInterface;
use MageUpgrade\AutoUpgrader\Api\Data\UpgradeLogInterface;
use MageUpgrade\AutoUpgrader\Api\ExtensionManagerInterface;
use MageUpgrade\AutoUpgrader\Api\ProgressTrackerInterface;
use MageUpgrade\AutoUpgrader\Api\UpgradeManagerInterface;
use MageUpgrade\AutoUpgrader\Api\VersionResolverInterface;
use MageUpgrade\AutoUpgrader\Model\UpgradeLog;
use MageUpgrade\AutoUpgrader\Model\UpgradeLogFactory;
use MageUpgrade\AutoUpgrader\Model\ResourceModel\UpgradeLog as UpgradeLogResource;
use MageUpgrade\AutoUpgrader\Model\ResourceModel\UpgradeLog\CollectionFactory as UpgradeLogCollectionFactory;
use Psr\Log\LoggerInterface;

class UpgradeManager implements UpgradeManagerInterface
{
    public function __construct(
        private readonly UpgradeLogFactory $upgradeLogFactory,
        private readonly UpgradeLogResource $upgradeLogResource,
        private readonly UpgradeLogCollectionFactory $upgradeLogCollectionFactory,
        private readonly VersionResolverInterface $versionResolver,
        private readonly CompatibilityScannerInterface $scanner,
        private readonly AutoFixerInterface $autoFixer,
        private readonly ExtensionManagerInterface $extensionManager,
        private readonly BackupManagerInterface $backupManager,
        private readonly ProgressTrackerInterface $progressTracker,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly DirectoryList $directoryList,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function startUpgrade(string $targetVersion, bool $includePatches = true): UpgradeLogInterface
    {
        $currentVersion = $this->versionResolver->getCurrentVersion();

        /** @var UpgradeLog $upgradeLog */
        $upgradeLog = $this->upgradeLogFactory->create();
        $upgradeLog->setFromVersion($currentVersion);
        $upgradeLog->setToVersion($targetVersion);
        $upgradeLog->setStatus(UpgradeLogInterface::STATUS_SCANNING);
        $upgradeLog->setProgressPercent(0);
        $upgradeLog->setCurrentStep('Initializing...');
        $upgradeLog->setInitiatedBy('admin');
        $upgradeLog->setStepsLog($this->json->serialize([]));
        $this->upgradeLogResource->save($upgradeLog);

        $upgradeId = (int) $upgradeLog->getUpgradeId();

        try {
            // Step 1: Run compatibility scan
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_SCAN, 'running', 5, 'Scanning for compatibility issues...');
            $scanResult = $this->scanner->runScan($targetVersion);
            $upgradeLog->setScanId($scanResult->getScanId());
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_SCAN, 'completed', 15, sprintf(
                'Found %d issues (%d critical, %d auto-fixable)',
                $scanResult->getTotalIssues(),
                $scanResult->getCriticalIssues(),
                $scanResult->getAutoFixable()
            ));

            // Step 2: Check extension compatibility
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_EXTENSIONS, 'running', 20, 'Checking extension compatibility...');
            $extensionResults = $this->extensionManager->findCompatibleVersions($targetVersion);
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_EXTENSIONS, 'completed', 25, sprintf(
                'Checked %d extensions',
                count($extensionResults)
            ));

            // Set status to awaiting confirmation - user must approve before execution
            $upgradeLog->setStatus(UpgradeLogInterface::STATUS_AWAITING_CONFIRMATION);

            // Build summary for the user
            $summary = [
                'scan' => [
                    'scan_id' => $scanResult->getScanId(),
                    'total_issues' => $scanResult->getTotalIssues(),
                    'critical_issues' => $scanResult->getCriticalIssues(),
                    'warnings' => $scanResult->getWarnings(),
                    'auto_fixable' => $scanResult->getAutoFixable(),
                    'impacted_files' => $this->json->unserialize($scanResult->getImpactedFilesJson() ?? '[]'),
                ],
                'extensions' => $extensionResults,
                'upgrade_path' => [
                    'from' => $currentVersion,
                    'to' => $targetVersion,
                    'include_patches' => $includePatches,
                ],
            ];

            $stepsLog = $this->json->unserialize($upgradeLog->getStepsLog() ?? '{}');
            $stepsLog['summary'] = $summary;
            $upgradeLog->setStepsLog($this->json->serialize($stepsLog));
            $upgradeLog->setProgressPercent(25);
            $upgradeLog->setCurrentStep('Awaiting your confirmation to proceed');
            $this->upgradeLogResource->save($upgradeLog);

        } catch (\Exception $e) {
            $upgradeLog->setStatus(UpgradeLogInterface::STATUS_FAILED);
            $upgradeLog->setErrorMessage($e->getMessage());
            $this->upgradeLogResource->save($upgradeLog);
            $this->logger->error('AutoUpgrader: startUpgrade failed', ['error' => $e->getMessage()]);
        }

        return $upgradeLog;
    }

    public function confirmAndExecute(int $upgradeId): UpgradeLogInterface
    {
        /** @var UpgradeLog $upgradeLog */
        $upgradeLog = $this->upgradeLogFactory->create();
        $this->upgradeLogResource->load($upgradeLog, $upgradeId);

        if (!$upgradeLog->getUpgradeId()) {
            throw new \Magento\Framework\Exception\NoSuchEntityException(
                __('Upgrade with ID "%1" does not exist.', $upgradeId)
            );
        }

        if ($upgradeLog->getStatus() !== UpgradeLogInterface::STATUS_AWAITING_CONFIRMATION) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Upgrade is not in awaiting confirmation state. Current status: %1', $upgradeLog->getStatus())
            );
        }

        $upgradeLog->setStatus(UpgradeLogInterface::STATUS_BACKING_UP);
        $upgradeLog->setStartedAt(date('Y-m-d H:i:s'));
        $this->upgradeLogResource->save($upgradeLog);

        $targetVersion = $upgradeLog->getToVersion();
        $rootDir = $this->directoryList->getRoot();

        try {
            // Enable maintenance mode
            $this->maintenanceMode->set(true);

            // Step 3: Create backup
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_BACKUP, 'running', 30, 'Creating full backup...');
            $backup = $this->backupManager->createBackup('pre_upgrade_' . $targetVersion);
            $upgradeLog->setBackupPath($backup['path']);
            $this->upgradeLogResource->save($upgradeLog);
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_BACKUP, 'completed', 35, 'Backup created: ' . $backup['size']);

            // Step 4: Auto-fix issues
            $scanId = $upgradeLog->getScanId();
            if ($scanId) {
                $upgradeLog->setStatus(UpgradeLogInterface::STATUS_FIXING);
                $this->upgradeLogResource->save($upgradeLog);
                $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_AUTO_FIX, 'running', 40, 'Applying auto-fixes...');
                $fixResults = $this->autoFixer->applyFixes($scanId);
                $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_AUTO_FIX, 'completed', 45, sprintf(
                    'Fixed %d issues, %d failed',
                    $fixResults['fixed_count'],
                    $fixResults['failed_count']
                ));
            }

            // Step 5: Upgrade extensions
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_EXTENSIONS, 'running', 50, 'Upgrading extensions...');
            $stepsLog = $this->json->unserialize($upgradeLog->getStepsLog() ?? '{}');
            $extensions = $stepsLog['summary']['extensions'] ?? [];
            $extUpgraded = 0;
            foreach ($extensions as $ext) {
                if (($ext['status'] ?? '') === 'compatible' && !empty($ext['compatible_version'])) {
                    $this->extensionManager->upgradeExtension($ext['package_name'], $ext['compatible_version']);
                    $extUpgraded++;
                }
            }
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_EXTENSIONS, 'completed', 55, "Upgraded {$extUpgraded} extensions");

            // Step 6: Composer require magento/product-community-edition
            $upgradeLog->setStatus(UpgradeLogInterface::STATUS_UPGRADING);
            $this->upgradeLogResource->save($upgradeLog);
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_COMPOSER, 'running', 60, 'Running composer update...');

            $composerCmd = sprintf(
                'cd %s && composer require magento/product-community-edition=%s --no-update && composer update --no-interaction 2>&1',
                escapeshellarg($rootDir),
                escapeshellarg($targetVersion)
            );
            exec($composerCmd, $composerOutput, $composerReturn);

            if ($composerReturn !== 0) {
                throw new \RuntimeException('Composer update failed: ' . implode("\n", $composerOutput));
            }
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_COMPOSER, 'completed', 70, 'Composer update completed');

            // Step 7: setup:upgrade
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_SETUP, 'running', 75, 'Running setup:upgrade...');
            exec(sprintf('cd %s && php bin/magento setup:upgrade 2>&1', escapeshellarg($rootDir)), $setupOutput, $setupReturn);
            if ($setupReturn !== 0) {
                throw new \RuntimeException('setup:upgrade failed: ' . implode("\n", $setupOutput));
            }
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_SETUP, 'completed', 80, 'Setup upgrade completed');

            // Step 8: DI compile
            $upgradeLog->setStatus(UpgradeLogInterface::STATUS_COMPILING);
            $this->upgradeLogResource->save($upgradeLog);
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_COMPILE, 'running', 82, 'Compiling DI...');
            exec(sprintf('cd %s && php bin/magento setup:di:compile 2>&1', escapeshellarg($rootDir)), $compileOutput, $compileReturn);
            if ($compileReturn !== 0) {
                $this->logger->warning('DI compile had warnings: ' . implode("\n", $compileOutput));
            }
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_COMPILE, 'completed', 88, 'DI compilation completed');

            // Step 9: Static content deploy
            $upgradeLog->setStatus(UpgradeLogInterface::STATUS_DEPLOYING);
            $this->upgradeLogResource->save($upgradeLog);
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_STATIC, 'running', 90, 'Deploying static content...');
            exec(sprintf('cd %s && php bin/magento setup:static-content:deploy -f 2>&1', escapeshellarg($rootDir)));
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_STATIC, 'completed', 95, 'Static content deployed');

            // Step 10: Cache flush
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_CACHE, 'running', 96, 'Flushing cache...');
            exec(sprintf('cd %s && php bin/magento cache:flush 2>&1', escapeshellarg($rootDir)));
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_CACHE, 'completed', 97, 'Cache flushed');

            // Step 11: Verify
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_VERIFY, 'running', 98, 'Verifying upgrade...');
            exec(sprintf('cd %s && php bin/magento setup:db:status 2>&1', escapeshellarg($rootDir)), $verifyOutput);
            $this->progressTracker->updateProgress($upgradeId, ProgressTrackerInterface::STEP_VERIFY, 'completed', 100, 'Upgrade verified successfully');

            // Complete
            $upgradeLog->setStatus(UpgradeLogInterface::STATUS_COMPLETED);
            $upgradeLog->setProgressPercent(100);
            $upgradeLog->setCurrentStep('Upgrade completed successfully!');
            $upgradeLog->setCompletedAt(date('Y-m-d H:i:s'));

        } catch (\Exception $e) {
            $upgradeLog->setStatus(UpgradeLogInterface::STATUS_FAILED);
            $upgradeLog->setErrorMessage($e->getMessage());
            $this->logger->error('AutoUpgrader: Upgrade execution failed', [
                'upgrade_id' => $upgradeId,
                'error' => $e->getMessage()
            ]);
        } finally {
            $this->maintenanceMode->set(false);
            $this->upgradeLogResource->save($upgradeLog);
        }

        return $upgradeLog;
    }

    public function rollback(int $upgradeId): bool
    {
        /** @var UpgradeLog $upgradeLog */
        $upgradeLog = $this->upgradeLogFactory->create();
        $this->upgradeLogResource->load($upgradeLog, $upgradeId);

        if (!$upgradeLog->getUpgradeId()) {
            throw new \Magento\Framework\Exception\NoSuchEntityException(
                __('Upgrade with ID "%1" does not exist.', $upgradeId)
            );
        }

        $backupPath = $upgradeLog->getBackupPath();
        if (empty($backupPath)) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('No backup available for this upgrade.')
            );
        }

        try {
            $this->maintenanceMode->set(true);
            $result = $this->backupManager->restoreBackup($backupPath);

            if ($result) {
                $upgradeLog->setStatus(UpgradeLogInterface::STATUS_ROLLED_BACK);
                $this->upgradeLogResource->save($upgradeLog);

                $rootDir = $this->directoryList->getRoot();
                exec(sprintf('cd %s && php bin/magento setup:upgrade 2>&1', escapeshellarg($rootDir)));
                exec(sprintf('cd %s && php bin/magento cache:flush 2>&1', escapeshellarg($rootDir)));
            }

            return $result;
        } finally {
            $this->maintenanceMode->set(false);
        }
    }

    public function getHistory(): array
    {
        $collection = $this->upgradeLogCollectionFactory->create();
        $collection->setOrder('created_at', 'DESC');
        $collection->setPageSize(50);

        $history = [];
        foreach ($collection as $log) {
            $history[] = $log;
        }

        return $history;
    }
}

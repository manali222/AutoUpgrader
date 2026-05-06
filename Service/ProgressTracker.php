<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Service;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Serialize\Serializer\Json;
use MageUpgrade\AutoUpgrader\Api\ProgressTrackerInterface;
use MageUpgrade\AutoUpgrader\Model\UpgradeLog;
use MageUpgrade\AutoUpgrader\Model\UpgradeLogFactory;
use MageUpgrade\AutoUpgrader\Model\ResourceModel\UpgradeLog as UpgradeLogResource;
use Psr\Log\LoggerInterface;

class ProgressTracker implements ProgressTrackerInterface
{
    private const PROGRESS_FILE = 'autoupgrader_progress.json';

    // Keys must match what Execute.php passes to updateStep() and the JS timeline IDs (ts-{key})
    private const STEPS_ORDER = [
        'backup' => ['label' => 'Creating Backup', 'weight' => 10],
        'auto_fix' => ['label' => 'Auto-Fixing Issues', 'weight' => 10],
        'extensions' => ['label' => 'Upgrading Extensions', 'weight' => 15],
        'composer' => ['label' => 'Composer Update', 'weight' => 20],
        'setup' => ['label' => 'Setup Upgrade', 'weight' => 10],
        'compile' => ['label' => 'DI Compilation', 'weight' => 10],
        'static' => ['label' => 'Static Content Deploy', 'weight' => 10],
        'cache' => ['label' => 'Cache Flush', 'weight' => 2],
        'verify' => ['label' => 'Verification', 'weight' => 3],
    ];

    public function __construct(
        private readonly UpgradeLogFactory $upgradeLogFactory,
        private readonly UpgradeLogResource $upgradeLogResource,
        private readonly DirectoryList $directoryList,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getProgress(int $upgradeId): array
    {
        /** @var UpgradeLog $upgradeLog */
        $upgradeLog = $this->upgradeLogFactory->create();
        $this->upgradeLogResource->load($upgradeLog, $upgradeId);

        if (!$upgradeLog->getUpgradeId()) {
            return ['error' => 'Upgrade not found'];
        }

        $stepsLog = $this->json->unserialize($upgradeLog->getStepsLog() ?? '{}');

        $steps = [];
        foreach (self::STEPS_ORDER as $stepKey => $stepInfo) {
            $stepData = $stepsLog[$stepKey] ?? [];
            $steps[] = [
                'key' => $stepKey,
                'label' => $stepInfo['label'],
                'status' => $stepData['status'] ?? 'pending',
                'message' => $stepData['message'] ?? '',
                'started_at' => $stepData['started_at'] ?? null,
                'completed_at' => $stepData['completed_at'] ?? null,
            ];
        }

        return [
            'upgrade_id' => $upgradeLog->getUpgradeId(),
            'from_version' => $upgradeLog->getFromVersion(),
            'to_version' => $upgradeLog->getToVersion(),
            'status' => $upgradeLog->getStatus(),
            'progress_percent' => $upgradeLog->getProgressPercent(),
            'current_step' => $upgradeLog->getCurrentStep(),
            'steps' => $steps,
            'error_message' => $upgradeLog->getErrorMessage(),
            'started_at' => $upgradeLog->getStartedAt(),
            'completed_at' => $upgradeLog->getCompletedAt(),
        ];
    }

    public function updateProgress(
        int $upgradeId,
        string $step,
        string $status,
        int $percent,
        ?string $message = null
    ): void {
        /** @var UpgradeLog $upgradeLog */
        $upgradeLog = $this->upgradeLogFactory->create();
        $this->upgradeLogResource->load($upgradeLog, $upgradeId);

        if (!$upgradeLog->getUpgradeId()) {
            return;
        }

        $stepsLog = $this->json->unserialize($upgradeLog->getStepsLog() ?? '{}');
        $stepsLog[$step] = [
            'status' => $status,
            'message' => $message ?? '',
            'started_at' => $stepsLog[$step]['started_at'] ?? date('Y-m-d H:i:s'),
            'completed_at' => $status === 'completed' ? date('Y-m-d H:i:s') : null,
        ];

        $upgradeLog->setStepsLog($this->json->serialize($stepsLog));
        $upgradeLog->setProgressPercent($percent);
        $upgradeLog->setCurrentStep(self::STEPS_ORDER[$step]['label'] ?? $step);

        $this->upgradeLogResource->save($upgradeLog);

        // Also write to JSON file for pub/autoupgrader_status.php polling
        $isComplete = ($status === 'completed' && $step === 'verify') || $percent >= 100;
        $this->writeProgressFile($upgradeLog, $stepsLog, $percent, $isComplete);
    }

    /**
     * Initialize progress file with a token. Call before starting the upgrade.
     */
    public function initProgressFile(int $upgradeId, string $token): void
    {
        $data = [
            'token' => $token,
            'upgrade_id' => $upgradeId,
            'status' => 'running',
            'progress_percent' => 0,
            'current_step' => 'Starting...',
            'steps' => [],
        ];

        $filePath = $this->getProgressFilePath();
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function writeProgressFile(UpgradeLog $upgradeLog, array $stepsLog, int $percent, bool $isComplete): void
    {
        $filePath = $this->getProgressFilePath();

        // Read existing file to preserve token
        $existing = [];
        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            $existing = json_decode($content, true) ?: [];
        }

        $steps = [];
        foreach (self::STEPS_ORDER as $stepKey => $stepInfo) {
            $stepData = $stepsLog[$stepKey] ?? [];
            $steps[] = [
                'key' => $stepKey,
                'label' => $stepInfo['label'],
                'status' => $stepData['status'] ?? 'pending',
                'message' => $stepData['message'] ?? '',
            ];
        }

        $overallStatus = $isComplete ? 'completed' : ($upgradeLog->getStatus() === 'failed' ? 'failed' : 'running');

        $data = [
            'token' => $existing['token'] ?? '',
            'upgrade_id' => (int) $upgradeLog->getUpgradeId(),
            'from_version' => $upgradeLog->getFromVersion(),
            'to_version' => $upgradeLog->getToVersion(),
            'status' => $overallStatus,
            'progress_percent' => $percent,
            'current_step' => $upgradeLog->getCurrentStep(),
            'steps' => $steps,
            'error_message' => $upgradeLog->getErrorMessage(),
        ];

        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function getProgressFilePath(): string
    {
        return $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/' . self::PROGRESS_FILE;
    }
}

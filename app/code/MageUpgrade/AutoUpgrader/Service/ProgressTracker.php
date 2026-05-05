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

    private const STEPS_ORDER = [
        self::STEP_BACKUP => ['label' => 'Creating Backup', 'weight' => 10],
        self::STEP_SCAN => ['label' => 'Compatibility Scan', 'weight' => 10],
        self::STEP_AUTO_FIX => ['label' => 'Auto-Fixing Issues', 'weight' => 10],
        self::STEP_EXTENSIONS => ['label' => 'Upgrading Extensions', 'weight' => 15],
        self::STEP_COMPOSER => ['label' => 'Composer Update', 'weight' => 20],
        self::STEP_SETUP => ['label' => 'Setup Upgrade', 'weight' => 10],
        self::STEP_COMPILE => ['label' => 'DI Compilation', 'weight' => 10],
        self::STEP_STATIC => ['label' => 'Static Content Deploy', 'weight' => 10],
        self::STEP_CACHE => ['label' => 'Cache Flush', 'weight' => 2],
        self::STEP_VERIFY => ['label' => 'Verification', 'weight' => 3],
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

        // Also write to file for standalone status endpoint
        $this->updateFileProgress($upgradeId, $upgradeLog, $stepsLog, $percent, $step);
    }

    /**
     * Write progress data to a JSON file that can be read without Magento bootstrap.
     */
    public function updateFileProgress(
        int $upgradeId,
        ?UpgradeLog $upgradeLog = null,
        ?array $stepsLog = null,
        ?int $percent = null,
        ?string $currentStep = null
    ): void {
        try {
            if ($upgradeLog === null) {
                $upgradeLog = $this->upgradeLogFactory->create();
                $this->upgradeLogResource->load($upgradeLog, $upgradeId);
                if (!$upgradeLog->getUpgradeId()) {
                    return;
                }
            }

            if ($stepsLog === null) {
                $stepsLog = $this->json->unserialize($upgradeLog->getStepsLog() ?? '{}');
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

            $fileData = $this->readFileProgress();
            $token = $fileData['token'] ?? null;

            $data = [
                'upgrade_id' => $upgradeId,
                'token' => $token,
                'status' => $upgradeLog->getStatus(),
                'progress_percent' => $percent ?? $upgradeLog->getProgressPercent(),
                'current_step' => self::STEPS_ORDER[$currentStep]['label'] ?? $currentStep ?? $upgradeLog->getCurrentStep(),
                'from_version' => $upgradeLog->getFromVersion(),
                'to_version' => $upgradeLog->getToVersion(),
                'steps' => $steps,
                'error_message' => $upgradeLog->getErrorMessage(),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $filePath = $this->getProgressFilePath();
            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to write progress file: ' . $e->getMessage());
        }
    }

    /**
     * Initialize the progress file with token for a new upgrade run.
     */
    public function initFileProgress(int $upgradeId, string $token, string $fromVersion, string $toVersion): void
    {
        $steps = [];
        foreach (self::STEPS_ORDER as $stepKey => $stepInfo) {
            $steps[] = [
                'key' => $stepKey,
                'label' => $stepInfo['label'],
                'status' => 'pending',
                'message' => '',
            ];
        }

        $data = [
            'upgrade_id' => $upgradeId,
            'token' => $token,
            'status' => 'in_progress',
            'progress_percent' => 0,
            'current_step' => 'Initializing...',
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
            'steps' => $steps,
            'error_message' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $filePath = $this->getProgressFilePath();
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Read progress from the JSON file.
     */
    public function readFileProgress(): array
    {
        $filePath = $this->getProgressFilePath();
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    public function getProgressFilePath(): string
    {
        return $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/' . self::PROGRESS_FILE;
    }
}

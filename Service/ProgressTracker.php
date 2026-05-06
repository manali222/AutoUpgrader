<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Service;

use Magento\Framework\Serialize\Serializer\Json;
use MageUpgrade\AutoUpgrader\Api\ProgressTrackerInterface;
use MageUpgrade\AutoUpgrader\Model\UpgradeLog;
use MageUpgrade\AutoUpgrader\Model\UpgradeLogFactory;
use MageUpgrade\AutoUpgrader\Model\ResourceModel\UpgradeLog as UpgradeLogResource;
use Psr\Log\LoggerInterface;

class ProgressTracker implements ProgressTrackerInterface
{
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
    }
}

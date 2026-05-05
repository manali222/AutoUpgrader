<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Api\Data;

interface UpgradeLogInterface
{
    public const UPGRADE_ID = 'upgrade_id';
    public const FROM_VERSION = 'from_version';
    public const TO_VERSION = 'to_version';
    public const STATUS = 'status';
    public const PROGRESS_PERCENT = 'progress_percent';
    public const CURRENT_STEP = 'current_step';
    public const STEPS_LOG = 'steps_log';
    public const ERROR_MESSAGE = 'error_message';
    public const BACKUP_PATH = 'backup_path';
    public const SCAN_ID = 'scan_id';
    public const INITIATED_BY = 'initiated_by';
    public const STARTED_AT = 'started_at';
    public const COMPLETED_AT = 'completed_at';

    public const STATUS_PENDING = 'pending';
    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';
    public const STATUS_SCANNING = 'scanning';
    public const STATUS_FIXING = 'fixing';
    public const STATUS_BACKING_UP = 'backing_up';
    public const STATUS_UPGRADING = 'upgrading';
    public const STATUS_COMPILING = 'compiling';
    public const STATUS_DEPLOYING = 'deploying';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    public function getUpgradeId(): ?int;
    public function getFromVersion(): string;
    public function setFromVersion(string $version): self;
    public function getToVersion(): string;
    public function setToVersion(string $version): self;
    public function getStatus(): string;
    public function setStatus(string $status): self;
    public function getProgressPercent(): int;
    public function setProgressPercent(int $percent): self;
    public function getCurrentStep(): ?string;
    public function setCurrentStep(?string $step): self;
    public function getStepsLog(): ?string;
    public function setStepsLog(?string $log): self;
    public function getErrorMessage(): ?string;
    public function setErrorMessage(?string $message): self;
    public function getBackupPath(): ?string;
    public function setBackupPath(?string $path): self;
    public function getScanId(): ?int;
    public function setScanId(?int $scanId): self;
    public function getInitiatedBy(): ?string;
    public function setInitiatedBy(?string $by): self;
    public function getStartedAt(): ?string;
    public function setStartedAt(?string $date): self;
    public function getCompletedAt(): ?string;
    public function setCompletedAt(?string $date): self;
}

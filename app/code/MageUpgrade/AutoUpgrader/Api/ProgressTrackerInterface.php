<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Api;

interface ProgressTrackerInterface
{
    public const STEP_BACKUP = 'backup';
    public const STEP_SCAN = 'compatibility_scan';
    public const STEP_AUTO_FIX = 'auto_fix';
    public const STEP_EXTENSIONS = 'upgrade_extensions';
    public const STEP_COMPOSER = 'composer_update';
    public const STEP_SETUP = 'setup_upgrade';
    public const STEP_COMPILE = 'di_compile';
    public const STEP_STATIC = 'static_deploy';
    public const STEP_CACHE = 'cache_flush';
    public const STEP_VERIFY = 'verification';

    /**
     * Get current progress for an upgrade
     *
     * @param int $upgradeId
     * @return mixed[] Array with progress details
     */
    public function getProgress(int $upgradeId): array;

    /**
     * Update progress for an upgrade step
     *
     * @param int $upgradeId
     * @param string $step
     * @param string $status
     * @param int $percent
     * @param string|null $message
     * @return void
     */
    public function updateProgress(int $upgradeId, string $step, string $status, int $percent, ?string $message = null): void;
}

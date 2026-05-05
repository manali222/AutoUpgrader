<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Api;

interface UpgradeManagerInterface
{
    /**
     * Start an upgrade process (creates plan, does NOT execute yet)
     *
     * @param string $targetVersion
     * @param bool $includePatches
     * @return \MageUpgrade\AutoUpgrader\Api\Data\UpgradeLogInterface
     */
    public function startUpgrade(string $targetVersion, bool $includePatches = true): Data\UpgradeLogInterface;

    /**
     * User confirms and the upgrade executes
     *
     * @param int $upgradeId
     * @return \MageUpgrade\AutoUpgrader\Api\Data\UpgradeLogInterface
     */
    public function confirmAndExecute(int $upgradeId): Data\UpgradeLogInterface;

    /**
     * Rollback a completed or failed upgrade
     *
     * @param int $upgradeId
     * @return bool
     */
    public function rollback(int $upgradeId): bool;

    /**
     * Get upgrade history
     *
     * @return \MageUpgrade\AutoUpgrader\Api\Data\UpgradeLogInterface[]
     */
    public function getHistory(): array;
}

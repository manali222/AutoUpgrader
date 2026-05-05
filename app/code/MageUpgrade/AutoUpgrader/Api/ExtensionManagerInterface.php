<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Api;

interface ExtensionManagerInterface
{
    /**
     * Get all installed third-party extensions
     *
     * @return mixed[] Array of extension info
     */
    public function getInstalledExtensions(): array;

    /**
     * Find compatible versions for all extensions for a target Magento version
     *
     * @param string $targetVersion
     * @return mixed[] Array of extension compatibility data
     */
    public function findCompatibleVersions(string $targetVersion): array;

    /**
     * Upgrade a specific extension to a compatible version
     *
     * @param string $packageName
     * @param string $targetVersion
     * @return bool
     */
    public function upgradeExtension(string $packageName, string $targetVersion): bool;
}

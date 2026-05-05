<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Api;

interface VersionResolverInterface
{
    /**
     * Get available Magento versions for upgrade
     *
     * @return string[] Array of version strings with metadata
     */
    public function getAvailableVersions(): array;

    /**
     * Get the current installed Magento version
     *
     * @return string
     */
    public function getCurrentVersion(): string;

    /**
     * Get available patches for a specific version
     *
     * @param string $version
     * @return string[]
     */
    public function getAvailablePatches(string $version): array;
}

<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Api;

interface CompatibilityScannerInterface
{
    /**
     * Run a compatibility scan against a target version
     *
     * @param string $targetVersion
     * @return \MageUpgrade\AutoUpgrader\Api\Data\ScanResultInterface
     */
    public function runScan(string $targetVersion): Data\ScanResultInterface;

    /**
     * Get scan results by scan ID
     *
     * @param int $scanId
     * @return \MageUpgrade\AutoUpgrader\Api\Data\ScanResultInterface
     */
    public function getScanResults(int $scanId): Data\ScanResultInterface;
}

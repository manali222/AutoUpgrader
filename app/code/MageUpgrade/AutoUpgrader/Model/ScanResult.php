<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Model;

use Magento\Framework\Model\AbstractModel;
use MageUpgrade\AutoUpgrader\Api\Data\ScanResultInterface;
use MageUpgrade\AutoUpgrader\Model\ResourceModel\ScanResult as ScanResultResource;

class ScanResult extends AbstractModel implements ScanResultInterface
{
    protected function _construct(): void
    {
        $this->_init(ScanResultResource::class);
    }

    public function getScanId(): ?int
    {
        $id = $this->getData(self::SCAN_ID);
        return $id !== null ? (int) $id : null;
    }

    public function getCurrentVersion(): string
    {
        return (string) $this->getData(self::CURRENT_VERSION);
    }

    public function setCurrentVersion(string $version): ScanResultInterface
    {
        return $this->setData(self::CURRENT_VERSION, $version);
    }

    public function getTargetVersion(): string
    {
        return (string) $this->getData(self::TARGET_VERSION);
    }

    public function setTargetVersion(string $version): ScanResultInterface
    {
        return $this->setData(self::TARGET_VERSION, $version);
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::STATUS);
    }

    public function setStatus(string $status): ScanResultInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getTotalIssues(): int
    {
        return (int) $this->getData(self::TOTAL_ISSUES);
    }

    public function setTotalIssues(int $count): ScanResultInterface
    {
        return $this->setData(self::TOTAL_ISSUES, $count);
    }

    public function getCriticalIssues(): int
    {
        return (int) $this->getData(self::CRITICAL_ISSUES);
    }

    public function setCriticalIssues(int $count): ScanResultInterface
    {
        return $this->setData(self::CRITICAL_ISSUES, $count);
    }

    public function getWarnings(): int
    {
        return (int) $this->getData(self::WARNINGS);
    }

    public function setWarnings(int $count): ScanResultInterface
    {
        return $this->setData(self::WARNINGS, $count);
    }

    public function getAutoFixable(): int
    {
        return (int) $this->getData(self::AUTO_FIXABLE);
    }

    public function setAutoFixable(int $count): ScanResultInterface
    {
        return $this->setData(self::AUTO_FIXABLE, $count);
    }

    public function getIssuesJson(): ?string
    {
        return $this->getData(self::ISSUES_JSON);
    }

    public function setIssuesJson(?string $json): ScanResultInterface
    {
        return $this->setData(self::ISSUES_JSON, $json);
    }

    public function getExtensionsJson(): ?string
    {
        return $this->getData(self::EXTENSIONS_JSON);
    }

    public function setExtensionsJson(?string $json): ScanResultInterface
    {
        return $this->setData(self::EXTENSIONS_JSON, $json);
    }

    public function getImpactedFilesJson(): ?string
    {
        return $this->getData(self::IMPACTED_FILES_JSON);
    }

    public function setImpactedFilesJson(?string $json): ScanResultInterface
    {
        return $this->setData(self::IMPACTED_FILES_JSON, $json);
    }
}

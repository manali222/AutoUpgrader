<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Model;

use Magento\Framework\Model\AbstractModel;
use MageUpgrade\AutoUpgrader\Api\Data\UpgradeLogInterface;
use MageUpgrade\AutoUpgrader\Model\ResourceModel\UpgradeLog as UpgradeLogResource;

class UpgradeLog extends AbstractModel implements UpgradeLogInterface
{
    protected function _construct(): void
    {
        $this->_init(UpgradeLogResource::class);
    }

    public function getUpgradeId(): ?int
    {
        $id = $this->getData(self::UPGRADE_ID);
        return $id !== null ? (int) $id : null;
    }

    public function getFromVersion(): string
    {
        return (string) $this->getData(self::FROM_VERSION);
    }

    public function setFromVersion(string $version): UpgradeLogInterface
    {
        return $this->setData(self::FROM_VERSION, $version);
    }

    public function getToVersion(): string
    {
        return (string) $this->getData(self::TO_VERSION);
    }

    public function setToVersion(string $version): UpgradeLogInterface
    {
        return $this->setData(self::TO_VERSION, $version);
    }

    public function getStatus(): string
    {
        return (string) $this->getData(self::STATUS);
    }

    public function setStatus(string $status): UpgradeLogInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getProgressPercent(): int
    {
        return (int) $this->getData(self::PROGRESS_PERCENT);
    }

    public function setProgressPercent(int $percent): UpgradeLogInterface
    {
        return $this->setData(self::PROGRESS_PERCENT, $percent);
    }

    public function getCurrentStep(): ?string
    {
        return $this->getData(self::CURRENT_STEP);
    }

    public function setCurrentStep(?string $step): UpgradeLogInterface
    {
        return $this->setData(self::CURRENT_STEP, $step);
    }

    public function getStepsLog(): ?string
    {
        return $this->getData(self::STEPS_LOG);
    }

    public function setStepsLog(?string $log): UpgradeLogInterface
    {
        return $this->setData(self::STEPS_LOG, $log);
    }

    public function getErrorMessage(): ?string
    {
        return $this->getData(self::ERROR_MESSAGE);
    }

    public function setErrorMessage(?string $message): UpgradeLogInterface
    {
        return $this->setData(self::ERROR_MESSAGE, $message);
    }

    public function getBackupPath(): ?string
    {
        return $this->getData(self::BACKUP_PATH);
    }

    public function setBackupPath(?string $path): UpgradeLogInterface
    {
        return $this->setData(self::BACKUP_PATH, $path);
    }

    public function getScanId(): ?int
    {
        $id = $this->getData(self::SCAN_ID);
        return $id !== null ? (int) $id : null;
    }

    public function setScanId(?int $scanId): UpgradeLogInterface
    {
        return $this->setData(self::SCAN_ID, $scanId);
    }

    public function getInitiatedBy(): ?string
    {
        return $this->getData(self::INITIATED_BY);
    }

    public function setInitiatedBy(?string $by): UpgradeLogInterface
    {
        return $this->setData(self::INITIATED_BY, $by);
    }

    public function getStartedAt(): ?string
    {
        return $this->getData(self::STARTED_AT);
    }

    public function setStartedAt(?string $date): UpgradeLogInterface
    {
        return $this->setData(self::STARTED_AT, $date);
    }

    public function getCompletedAt(): ?string
    {
        return $this->getData(self::COMPLETED_AT);
    }

    public function setCompletedAt(?string $date): UpgradeLogInterface
    {
        return $this->setData(self::COMPLETED_AT, $date);
    }
}

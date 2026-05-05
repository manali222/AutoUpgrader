<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Api\Data;

interface ScanResultInterface
{
    public const SCAN_ID = 'scan_id';
    public const CURRENT_VERSION = 'current_version';
    public const TARGET_VERSION = 'target_version';
    public const STATUS = 'status';
    public const TOTAL_ISSUES = 'total_issues';
    public const CRITICAL_ISSUES = 'critical_issues';
    public const WARNINGS = 'warnings';
    public const AUTO_FIXABLE = 'auto_fixable';
    public const ISSUES_JSON = 'issues_json';
    public const EXTENSIONS_JSON = 'extensions_json';
    public const IMPACTED_FILES_JSON = 'impacted_files_json';

    public function getScanId(): ?int;
    public function getCurrentVersion(): string;
    public function setCurrentVersion(string $version): self;
    public function getTargetVersion(): string;
    public function setTargetVersion(string $version): self;
    public function getStatus(): string;
    public function setStatus(string $status): self;
    public function getTotalIssues(): int;
    public function setTotalIssues(int $count): self;
    public function getCriticalIssues(): int;
    public function setCriticalIssues(int $count): self;
    public function getWarnings(): int;
    public function setWarnings(int $count): self;
    public function getAutoFixable(): int;
    public function setAutoFixable(int $count): self;
    public function getIssuesJson(): ?string;
    public function setIssuesJson(?string $json): self;
    public function getExtensionsJson(): ?string;
    public function setExtensionsJson(?string $json): self;
    public function getImpactedFilesJson(): ?string;
    public function setImpactedFilesJson(?string $json): self;
}

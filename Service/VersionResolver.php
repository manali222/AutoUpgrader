<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Service;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use MageUpgrade\AutoUpgrader\Api\VersionResolverInterface;
use Psr\Log\LoggerInterface;

class VersionResolver implements VersionResolverInterface
{
    private const PACKAGIST_API = 'https://repo.packagist.org/p2/magento/project-community-edition.json';

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getAvailableVersions(): array
    {
        $currentVersion = $this->getCurrentVersion();
        $versions = [];

        try {
            $this->curl->get(self::PACKAGIST_API);
            $response = $this->curl->getBody();
            $data = $this->json->unserialize($response);

            $packages = $data['packages']['magento/project-community-edition'] ?? [];

            foreach ($packages as $package) {
                $version = $package['version'] ?? '';
                // Only include stable versions newer than current
                if ($this->isValidUpgradeTarget($version, $currentVersion)) {
                    $versions[] = [
                        'version' => $version,
                        'php_requirement' => $package['require']['php'] ?? 'N/A',
                        'release_date' => $package['time'] ?? '',
                        'is_patch' => str_contains($version, '-p'),
                        'is_security' => str_contains(strtolower($package['description'] ?? ''), 'security'),
                    ];
                }
            }

            // Sort newest first
            usort($versions, fn(array $a, array $b) => version_compare($b['version'], $a['version']));
        } catch (\Exception $e) {
            $this->logger->error('AutoUpgrader: Failed to fetch versions from Packagist', [
                'error' => $e->getMessage()
            ]);
            // Return hardcoded fallback for offline environments
            $versions = $this->getFallbackVersions($currentVersion);
        }

        return $versions;
    }

    public function getCurrentVersion(): string
    {
        return $this->productMetadata->getVersion();
    }

    public function getAvailablePatches(string $version): array
    {
        $allVersions = $this->getAvailableVersions();
        $baseVersion = preg_replace('/-p\d+$/', '', $version);
        $patches = [];

        foreach ($allVersions as $v) {
            if (str_starts_with($v['version'], $baseVersion . '-p')) {
                $patches[] = $v['version'];
            }
        }

        return $patches;
    }

    private function isValidUpgradeTarget(string $version, string $currentVersion): bool
    {
        // Skip dev, alpha, beta, RC versions
        if (preg_match('/(dev|alpha|beta|rc)/i', $version)) {
            return false;
        }
        // Must be newer than current
        return version_compare($version, $currentVersion, '>');
    }

    private function getFallbackVersions(string $currentVersion): array
    {
        $knownVersions = [
            '2.4.8', '2.4.8-p1', '2.4.8-p2', '2.4.8-p3', '2.4.8-p4',
            '2.4.7-p5', '2.4.7-p4', '2.4.7-p3', '2.4.7-p2', '2.4.7-p1', '2.4.7',
            '2.4.6-p8', '2.4.6-p7', '2.4.6-p6', '2.4.6-p5', '2.4.6-p4', '2.4.6-p3', '2.4.6-p2', '2.4.6-p1', '2.4.6',
            '2.4.5-p10', '2.4.5-p9', '2.4.5-p8', '2.4.5-p7', '2.4.5-p6', '2.4.5-p5', '2.4.5-p4', '2.4.5-p3', '2.4.5-p2', '2.4.5-p1', '2.4.5',
        ];

        $versions = [];
        foreach ($knownVersions as $v) {
            if (version_compare($v, $currentVersion, '>')) {
                $versions[] = [
                    'version' => $v,
                    'php_requirement' => 'Check release notes',
                    'release_date' => '',
                    'is_patch' => str_contains($v, '-p'),
                    'is_security' => false,
                ];
            }
        }
        return $versions;
    }
}

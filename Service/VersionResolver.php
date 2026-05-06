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
    private const GITHUB_API = 'https://api.github.com/repos/magento/magento2/tags?per_page=50';

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
        $composerVersions = $this->getComposerAvailableVersions();
        $versions = [];

        // If we got versions from Composer repo, use those as the source of truth
        if (!empty($composerVersions)) {
            foreach ($composerVersions as $version) {
                if ($this->isValidUpgradeTarget($version, $currentVersion)) {
                    $versions[] = [
                        'version' => $version,
                        'php_requirement' => $this->getPhpRequirement($version),
                        'release_date' => '',
                        'is_patch' => str_contains($version, '-p'),
                        'is_security' => str_contains($version, '-p'),
                    ];
                }
            }
        }

        // Fallback: try GitHub tags, but validate against Composer if available
        if (empty($versions)) {
            try {
                $this->curl->addHeader('User-Agent', 'MageUpgrade-AutoUpgrader/1.0');
                $this->curl->addHeader('Accept', 'application/vnd.github.v3+json');
                $this->curl->get(self::GITHUB_API);
                $response = $this->curl->getBody();
                $tags = $this->json->unserialize($response);

                if (is_array($tags)) {
                    foreach ($tags as $tag) {
                        $version = ltrim($tag['name'] ?? '', 'v');
                        if ($this->isValidUpgradeTarget($version, $currentVersion)) {
                            // If we have composer data, only include versions that exist there
                            if (!empty($composerVersions) && !in_array($version, $composerVersions, true)) {
                                continue;
                            }
                            $versions[] = [
                                'version' => $version,
                                'php_requirement' => $this->getPhpRequirement($version),
                                'release_date' => '',
                                'is_patch' => str_contains($version, '-p'),
                                'is_security' => str_contains($version, '-p'),
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('AutoUpgrader: Failed to fetch versions from GitHub', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Sort newest first
        usort($versions, fn(array $a, array $b) => version_compare(
            $this->normalizePatchVersion($b['version']),
            $this->normalizePatchVersion($a['version'])
        ));

        // Last resort fallback filtered by Composer availability
        if (empty($versions)) {
            $versions = $this->getFallbackVersions($currentVersion, $composerVersions);
        }

        return $versions;
    }

    /**
     * Get versions available in the Composer repo (repo.magento.com).
     */
    private function getComposerAvailableVersions(): array
    {
        try {
            $output = [];
            $returnCode = 0;
            exec('composer show magento/product-community-edition --available --name-only --format=json 2>/dev/null', $output, $returnCode);

            if ($returnCode !== 0 || empty($output)) {
                return [];
            }

            $json = implode('', $output);
            $data = json_decode($json, true);

            if (!is_array($data) || empty($data['versions'])) {
                return [];
            }

            return $data['versions'];
        } catch (\Exception $e) {
            $this->logger->warning('AutoUpgrader: Could not query Composer for available versions', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    private function getPhpRequirement(string $version): string
    {
        $base = preg_replace('/-p\d+$/', '', $version);
        return match (true) {
            version_compare($base, '2.4.8', '>=') => '~8.3.0 || ~8.4.0',
            version_compare($base, '2.4.7', '>=') => '~8.2.0 || ~8.3.0',
            version_compare($base, '2.4.6', '>=') => '~8.1.0 || ~8.2.0',
            default => '~8.1.0',
        };
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
        // PHP's version_compare treats -p as pre-release (lower), so we
        // normalize "2.4.8-p2" → "2.4.8.2" for correct comparison
        return version_compare(
            $this->normalizePatchVersion($version),
            $this->normalizePatchVersion($currentVersion),
            '>'
        );
    }

    /**
     * Convert Magento patch notation to dot notation for proper comparison.
     * "2.4.8"    → "2.4.8.0"
     * "2.4.8-p3" → "2.4.8.3"
     */
    private function normalizePatchVersion(string $version): string
    {
        if (preg_match('/^(.+)-p(\d+)$/', $version, $m)) {
            return $m[1] . '.' . $m[2];
        }
        return $version . '.0';
    }

    private function getFallbackVersions(string $currentVersion, array $composerVersions = []): array
    {
        $knownVersions = [
            '2.4.9', '2.4.8-p5', '2.4.8-p4', '2.4.8-p3', '2.4.8-p2', '2.4.8-p1', '2.4.8',
            '2.4.7-p4', '2.4.7-p3', '2.4.7-p2', '2.4.7-p1', '2.4.7',
        ];

        $versions = [];
        foreach ($knownVersions as $v) {
            if (!$this->isValidUpgradeTarget($v, $currentVersion)) {
                continue;
            }
            // If we have Composer data, only include versions that exist there
            if (!empty($composerVersions) && !in_array($v, $composerVersions, true)) {
                continue;
            }
            $versions[] = [
                'version' => $v,
                'php_requirement' => $this->getPhpRequirement($v),
                'release_date' => '',
                'is_patch' => str_contains($v, '-p'),
                'is_security' => str_contains($v, '-p'),
            ];
        }
        return $versions;
    }
}

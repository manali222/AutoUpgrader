<?php
/**
 * Standalone status endpoint for AutoUpgrader progress.
 *
 * This file does NOT bootstrap Magento. It reads a JSON file written by the
 * ProgressTracker service, allowing progress polling to survive DI compile,
 * cache flush, and setup:upgrade steps that would kill a Magento-based endpoint.
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$token = $_GET['token'] ?? '';

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Token is required']);
    exit;
}

// Resolve var directory (pub/../var)
$varDir = dirname(__DIR__) . '/var';
$progressFile = $varDir . '/autoupgrader_progress.json';

if (!file_exists($progressFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'No upgrade in progress']);
    exit;
}

$content = file_get_contents($progressFile);
if ($content === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to read progress file']);
    exit;
}

$data = json_decode($content, true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid progress data']);
    exit;
}

// Validate token
if (!isset($data['token']) || !hash_equals($data['token'], $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

// Remove token from response
unset($data['token']);

echo json_encode(['success' => true, 'data' => $data]);

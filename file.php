<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$relative = (string) ($_GET['p'] ?? '');
$relative = str_replace('\\', '/', $relative);
if ($relative === '' || str_contains($relative, '..') || !preg_match('#^(inbox|library)(/[^/\\\\]+)+$#', $relative)) {
    http_response_code(400);
    echo 'Bad path';
    exit;
}

$full = storage_path($relative);
$base = realpath(storage_path()) ?: storage_path();
if (!is_file($full)) {
    http_response_code(404);
    echo 'Missing';
    exit;
}
$real = realpath($full);
if ($real === false || !str_starts_with($real, $base)) {
    http_response_code(400);
    echo 'Bad path';
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($full) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string) filesize($full));
readfile($full);

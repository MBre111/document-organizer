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
$rot = 0;
try {
    $pdo = db();
    if (column_exists($pdo, 'files', 'rotation')) {
        $st = $pdo->prepare('SELECT rotation FROM files WHERE stored_path = ? LIMIT 1');
        $st->execute([$relative]);
        $rot = (int) ($st->fetchColumn() ?: 0);
    }
} catch (Throwable $e) {
    $rot = 0;
}
header('X-Content-Type-Options: nosniff');
if ($rot && str_starts_with($mime, 'image/') && function_exists('imagerotate')) {
    $info = @getimagesize($full);
    $im = false;
    if ($info) {
        $im = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($full),
            IMAGETYPE_PNG => @imagecreatefrompng($full),
            IMAGETYPE_GIF => @imagecreatefromgif($full),
            default => false,
        };
    }
    if ($im) {
        $ccw = (360 - ($rot % 360)) % 360;
        $out = $ccw ? imagerotate($im, $ccw, 0) : $im;
        imagedestroy($im);
        header('Content-Type: image/jpeg');
        imagejpeg($out, null, 88);
        imagedestroy($out);
        exit;
    }
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($full));
readfile($full);

<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ingest.php';

$pdo = db();
$cli = (PHP_SAPI === 'cli');
$files = $pdo->query('SELECT * FROM files ORDER BY id')->fetchAll();
$ok = 0;
$empty = 0;
$skip = 0;
$err = 0;

foreach ($files as $file) {
    ocr_one_file($pdo, $file);
    $row = $pdo->prepare('SELECT ocr_status, CHAR_LENGTH(ocr_text) AS n FROM files WHERE id = ?');
    $row->execute([(int) $file['id']]);
    $st = $row->fetch();
    $status = (string) ($st['ocr_status'] ?? 'unknown');
    $n = (int) ($st['n'] ?? 0);
    if ($status === 'ok') {
        $ok++;
    } elseif ($status === 'empty') {
        $empty++;
    } elseif ($status === 'error') {
        $err++;
    } else {
        $skip++;
    }
    $line = sprintf(
        "#%d %s → %s (%d chars)\n",
        (int) $file['id'],
        $file['original_filename'],
        $status,
        $n
    );
    if ($cli) {
        echo $line;
    }
}

$summary = compact('ok', 'empty', 'skip', 'err');
if ($cli) {
    echo "done ok={$ok} empty={$empty} skipped={$skip} error={$err}\n";
    exit(0);
}

header('Content-Type: text/plain; charset=utf-8');
echo "OCR existing files\n";
echo "ok={$ok} empty={$empty} skipped={$skip} error={$err}\n";

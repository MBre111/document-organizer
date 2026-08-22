<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$pdo = db();
$ran = false;
$summary = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require __DIR__ . '/includes/ingest.php';
    $files = $pdo->query('SELECT * FROM files ORDER BY id')->fetchAll();
    $ok = $empty = $skip = $err = 0;
    foreach ($files as $file) {
        ocr_one_file($pdo, $file);
        $row = $pdo->prepare('SELECT ocr_status FROM files WHERE id = ?');
        $row->execute([(int) $file['id']]);
        $st = (string) ($row->fetchColumn() ?: 'skipped');
        if ($st === 'ok') {
            $ok++;
        } elseif ($st === 'empty') {
            $empty++;
        } elseif ($st === 'error') {
            $err++;
        } else {
            $skip++;
        }
    }
    $ran = true;
    $summary = "ok=$ok empty=$empty skipped=$skip error=$err";
}

$rows = $pdo->query(
    "SELECT id, original_filename, ocr_status, CHAR_LENGTH(ocr_text) AS n FROM files ORDER BY id"
)->fetchAll();

render_header('OCR', 'today');
?>
<p class="back"><a href="index.php">← Today</a></p>
<h1>OCR existing files</h1>
<p class="lede">Reads text from photos, PDFs, and Word files already in the archive. Google Doc stubs stay skipped until exported.</p>
<?php if ($ran): ?>
    <p class="flash ok"><?= h($summary) ?></p>
<?php endif; ?>
<form method="post">
    <button class="btn" type="submit">Run OCR on all files</button>
</form>
<ul class="docs">
<?php foreach ($rows as $row): ?>
    <li>
        <strong>#<?= (int) $row['id'] ?> <?= h((string) $row['original_filename']) ?></strong>
        <span class="meta"><?= h((string) ($row['ocr_status'] ?: 'none')) ?> · <?= (int) $row['n'] ?> chars</span>
    </li>
<?php endforeach; ?>
</ul>
<?php
render_footer();

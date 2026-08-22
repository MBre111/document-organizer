<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/ingest.php';
require __DIR__ . '/includes/layout.php';

$ajax = isset($_GET['ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = (($_POST['mode'] ?? 'merge') === 'separate') ? 'separate' : 'merge';
    $notes = (string) ($_POST['notes'] ?? '');
    $result = ['documents' => [], 'skipped' => [], 'errors' => []];
    try {
        $pdo = db();
        $result = store_upload_group($pdo, $_FILES['docs'] ?? [], $mode, $notes, request_source());
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
    }

    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        $ok = $result['documents'] && !$result['errors'];
        echo json_encode(['ok' => $ok] + $result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $messages = [];
    foreach ($result['documents'] as $doc) {
        $messages[] = 'Saved “' . $doc['title'] . '” (' . $doc['pages'] . ' page' . ($doc['pages'] === 1 ? '' : 's') . ').';
    }
    $errors = array_merge($result['errors'], $result['skipped']);
} else {
    $messages = [];
    $errors = [];
}

render_header('Upload', 'upload');
?>
<h1>Add documents</h1>
<p class="lede">Stack pages into a document, add a note, then send the whole batch. Camera adds one page at a time; the library can add many.</p>

<?php foreach ($errors as $e): ?>
    <p class="flash err"><?= h($e) ?></p>
<?php endforeach; ?>
<?php foreach ($messages as $m): ?>
    <p class="flash ok"><?= h($m) ?></p>
<?php endforeach; ?>

<div id="composer" class="composer">
    <section class="group-card" data-current>
        <div class="group-head">
            <h2>This document</h2>
            <label class="switch">
                <input type="checkbox" class="merge-toggle" checked>
                <span>Merge pages into one document</span>
            </label>
        </div>
        <label class="notes-label">Notes for this document
            <textarea class="group-notes" rows="3" placeholder="e.g. Bassett visit 8/12, 4 pages, keep with medical"></textarea>
        </label>
        <div class="pages" data-pages></div>
        <div class="add-row">
            <button type="button" class="btn ghost" data-add-camera>Take photo</button>
            <button type="button" class="btn ghost" data-add-files>Add files / pages</button>
        </div>
    </section>

    <p class="add-group-wrap">
        <button type="button" class="btn ghost" id="add-group">+ Another document</button>
    </p>

    <div id="queue" class="queue" hidden></div>

    <div class="sticky-send">
        <div class="progress" hidden><span></span></div>
        <button type="button" class="btn" id="send-all">Upload to inbox</button>
        <p class="muted" id="send-status"></p>
    </div>
</div>

<input id="pick-camera" type="file" accept="image/*" capture="environment" hidden>
<input id="pick-files" type="file" accept="image/*,application/pdf,.heic,.webp" multiple hidden>

<script src="assets/upload.js"></script>
<?php
render_footer();

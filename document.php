<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/facts_ui.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'save') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $docType = trim((string) ($_POST['doc_type'] ?? ''));
        $org = trim((string) ($_POST['source_org'] ?? ''));
        $docDate = trim((string) ($_POST['doc_date'] ?? ''));
        $status = trim((string) ($_POST['review_status'] ?? ''));
        $summary = trim((string) ($_POST['summary'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $caseId = (int) ($_POST['case_id'] ?? 0);
        $extraRaw = trim((string) ($_POST['extra_json'] ?? ''));
        $extra = $extraRaw === '' ? [] : json_decode($extraRaw, true);
        if (!is_array($extra)) {
            $extra = extra_decode($pdo->query('SELECT extra_json FROM documents WHERE id = ' . $id)->fetchColumn() ?: null);
        }
        $allowedStatus = ['inbox', 'extracted', 'confirmed', 'out_of_scope'];
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'extracted';
        }
        $pdo->prepare(
            'UPDATE documents SET title=?, doc_type=?, source_org=?, doc_date=?, review_status=?, summary=?, notes=?, extra_json=?, case_id=? WHERE id=?'
        )->execute([
            $title !== '' ? $title : null,
            $docType !== '' ? $docType : null,
            $org !== '' ? $org : null,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $docDate) ? $docDate : null,
            $status,
            $summary !== '' ? $summary : null,
            $notes !== '' ? $notes : null,
            extra_encode($extra),
            $caseId > 0 ? $caseId : null,
            $id,
        ]);
        set_document_tags($pdo, $id, (string) ($_POST['tags'] ?? ''));
        if ($status === 'confirmed') {
            materialize_document_google($pdo, $id);
            promote_document_files($pdo, $id);
        }
        seed_deadlines_from_document($pdo, $id, $extra, $title);
    } elseif ($action === 'add_link') {
        $to = (int) ($_POST['to_id'] ?? 0);
        $kind = (string) ($_POST['kind'] ?? 'related');
        if ($to > 0 && $to !== $id && in_array($kind, link_kinds(), true)) {
            $pdo->prepare('INSERT IGNORE INTO document_links (from_id, to_id, kind) VALUES (?, ?, ?)')
                ->execute([$id, $to, $kind]);
        }
    } elseif ($action === 'del_link') {
        $pdo->prepare('DELETE FROM document_links WHERE from_id = ? AND to_id = ? AND kind = ?')
            ->execute([$id, (int) ($_POST['to_id'] ?? 0), (string) ($_POST['kind'] ?? '')]);
    } elseif ($action === 'materialize') {
        materialize_document_google($pdo, $id);
    } elseif ($action === 'promote') {
        $pdo->prepare("UPDATE documents SET review_status = 'confirmed' WHERE id = ? AND review_status = 'extracted'")
            ->execute([$id]);
        materialize_document_google($pdo, $id);
        promote_document_files($pdo, $id);
    }
    header('Location: document.php?id=' . $id, true, 303);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ?');
$stmt->execute([$id]);
$doc = $stmt->fetch();
if (!$doc) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$files = $pdo->prepare('SELECT * FROM files WHERE document_id = ? ORDER BY page_no, id');
$files->execute([$id]);
$files = $files->fetchAll();
$pageTotal = count($files);

$tags = $pdo->prepare(
    'SELECT t.name FROM tags t
     JOIN document_tags dt ON dt.tag_id = t.id
     WHERE dt.document_id = ?
     ORDER BY t.name'
);
$tags->execute([$id]);
$tags = $tags->fetchAll(PDO::FETCH_COLUMN);

$entities = $pdo->prepare(
    'SELECT e.id, e.kind, e.name, de.role FROM entities e
     JOIN document_entities de ON de.entity_id = e.id
     WHERE de.document_id = ?
     ORDER BY e.kind, e.name'
);
$entities->execute([$id]);
$entities = $entities->fetchAll();

$extra = $doc['extra_json'] ? json_decode((string) $doc['extra_json'], true) : null;

$facts = $pdo->prepare(
    'SELECT * FROM untrusted_facts WHERE document_id = ? AND status = ? ORDER BY importance DESC, id'
);
$facts->execute([$id, 'open']);
$facts = $facts->fetchAll();

$links = [];
if (table_exists($pdo, 'document_links')) {
    $lq = $pdo->prepare(
        "SELECT l.kind, l.to_id AS other_id, d.title AS other_title, 'out' AS dir
         FROM document_links l JOIN documents d ON d.id = l.to_id
         WHERE l.from_id = ?
         UNION ALL
         SELECT l.kind, l.from_id AS other_id, d.title AS other_title, 'in' AS dir
         FROM document_links l JOIN documents d ON d.id = l.from_id
         WHERE l.to_id = ?
         ORDER BY kind, other_id"
    );
    $lq->execute([$id, $id]);
    $links = $lq->fetchAll();
}
$otherDocs = $pdo->query(
    "SELECT id, title FROM documents WHERE id <> " . $id . " AND review_status <> 'out_of_scope' ORDER BY id DESC LIMIT 80"
)->fetchAll();
$cases = table_exists($pdo, 'cases')
    ? $pdo->query('SELECT id, case_number, title FROM cases ORDER BY case_number')->fetchAll()
    : [];

render_header($doc['title'] ?: 'Document', $doc['review_status'] === 'inbox' ? 'inbox' : 'library');
?>
<p class="back"><a href="index.php<?= $doc['review_status'] === 'inbox' ? '' : '?view=library' ?>">← Back</a></p>
<h1><?= h($doc['title'] ?: 'Untitled') ?></h1>
<p class="meta">
    <?= h($doc['review_status']) ?>
    <?= $doc['doc_type'] ? ' · ' . h($doc['doc_type']) : '' ?>
    <?= $doc['source_org'] ? ' · ' . h($doc['source_org']) : '' ?>
    <?= $doc['doc_date'] ? ' · ' . h($doc['doc_date']) : '' ?>
    <?php if (!empty($doc['case_id'])): ?>
        · <a href="case.php?id=<?= (int) $doc['case_id'] ?>">case</a>
    <?php endif; ?>
</p>

<?php foreach ($files as $file): ?>
    <?php $isImage = str_starts_with((string) $file['mime'], 'image/'); ?>
    <figure class="preview">
        <?php if ($isImage): ?>
            <img src="<?= h(public_file_url($file['stored_path'])) ?>" alt="<?= h($file['original_filename']) ?>">
        <?php else: ?>
            <a href="<?= h(public_file_url($file['stored_path'])) ?>">Open <?= h($file['original_filename']) ?></a>
        <?php endif; ?>
        <figcaption>Page <?= (int) ($file['page_no'] ?? 1) ?> of <?= $pageTotal ?> · <?= h($file['original_filename']) ?> · <?= h($file['source']) ?><?php if (!empty($file['ocr_status'])): ?> · ocr <?= h((string) $file['ocr_status']) ?><?php endif; ?></figcaption>
    </figure>
<?php endforeach; ?>

<?php if ($facts): ?>
    <section class="untrusted">
        <h2>Needs confirming</h2>
        <p class="muted">Tap the bubble that is true. Type if none fit.</p>
        <ul class="facts">
            <?php foreach ($facts as $fact): ?>
                <?php render_fact_card($fact, 'document.php?id=' . $id); ?>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if ($doc['summary']): ?>
    <h2>Summary</h2>
    <p><?= nl2br(h($doc['summary'])) ?></p>
<?php endif; ?>

<?php if ($doc['notes']): ?>
    <h2>Notes</h2>
    <p><?= nl2br(h($doc['notes'])) ?></p>
<?php endif; ?>

<?php if ($tags): ?>
    <h2>Tags</h2>
    <p><?= h(implode(', ', $tags)) ?></p>
<?php endif; ?>

<?php if ($entities): ?>
    <h2>Connections</h2>
    <ul>
        <?php foreach ($entities as $ent): ?>
            <li><?= h($ent['kind']) ?>:
                <a href="entity.php?id=<?= (int) $ent['id'] ?>"><?= h($ent['name']) ?></a><?= $ent['role'] ? ' (' . h($ent['role']) . ')' : '' ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if (is_array($extra) && $extra): ?>
    <h2>Extracted fields</h2>
    <dl class="extra">
        <?php foreach ($extra as $k => $v): ?>
            <dt><?= h((string) $k) ?></dt>
            <dd><?php
                if ($k === 'case_number' && !empty($doc['case_id'])) {
                    echo '<a href="case.php?id=' . (int) $doc['case_id'] . '">' . h((string) $v) . '</a>';
                } else {
                    echo h(is_scalar($v) ? (string) $v : json_encode($v));
                }
            ?></dd>
        <?php endforeach; ?>
    </dl>
<?php endif; ?>

<?php if ($links): ?>
    <h2>Related</h2>
    <ul>
        <?php foreach ($links as $lnk): ?>
            <li>
                <?= $lnk['dir'] === 'out' ? h($lnk['kind']) . ' → ' : '← ' . h($lnk['kind']) . ' of ' ?>
                <a href="document.php?id=<?= (int) $lnk['other_id'] ?>"><?= h($lnk['other_title'] ?: 'Untitled') ?></a>
                <?php if ($lnk['dir'] === 'out'): ?>
                    <form method="post" class="inline-form">
                        <input type="hidden" name="action" value="del_link">
                        <input type="hidden" name="to_id" value="<?= (int) $lnk['other_id'] ?>">
                        <input type="hidden" name="kind" value="<?= h($lnk['kind']) ?>">
                        <button class="btn small ghost" type="submit">Remove</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($otherDocs): ?>
    <form method="post" class="link-add">
        <input type="hidden" name="action" value="add_link">
        <label>Link as
            <select name="kind">
                <?php foreach (link_kinds() as $k): ?>
                    <option value="<?= h($k) ?>"><?= h($k) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <select name="to_id">
            <option value="">Choose document…</option>
            <?php foreach ($otherDocs as $od): ?>
                <option value="<?= (int) $od['id'] ?>"><?= h($od['title'] ?: ('#' . $od['id'])) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn small" type="submit">Add link</button>
    </form>
<?php endif; ?>

<h2>Edit record</h2>
<form method="post" class="edit-doc">
    <input type="hidden" name="action" value="save">
    <label>Title <input name="title" value="<?= h((string) $doc['title']) ?>"></label>
    <label>Type
        <select name="doc_type">
            <option value="">—</option>
            <?php foreach (doc_types() as $t): ?>
                <option value="<?= h($t) ?>" <?= ($doc['doc_type'] ?? '') === $t ? 'selected' : '' ?>><?= h($t) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Organization <input name="source_org" value="<?= h((string) $doc['source_org']) ?>"></label>
    <label>Document date <input type="date" name="doc_date" value="<?= h((string) $doc['doc_date']) ?>"></label>
    <label>Status
        <select name="review_status">
            <?php foreach (['inbox', 'extracted', 'confirmed', 'out_of_scope'] as $st): ?>
                <option value="<?= h($st) ?>" <?= ($doc['review_status'] ?? '') === $st ? 'selected' : '' ?>><?= h($st) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Case
        <select name="case_id">
            <option value="0">None</option>
            <?php foreach ($cases as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) ($doc['case_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= h($c['case_number']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Tags (comma-separated) <input name="tags" value="<?= h(implode(', ', $tags)) ?>"></label>
    <label>Summary <textarea name="summary" rows="4"><?= h((string) $doc['summary']) ?></textarea></label>
    <label>Notes <textarea name="notes" rows="3"><?= h((string) $doc['notes']) ?></textarea></label>
    <label>Extra fields (JSON) <textarea name="extra_json" rows="6"><?= h($doc['extra_json'] ? (string) $doc['extra_json'] : '{}') ?></textarea></label>
    <button class="btn" type="submit">Save</button>
</form>
<div class="add-row">
    <form method="post"><input type="hidden" name="action" value="materialize"><button class="btn ghost" type="submit">Pull Google export</button></form>
    <form method="post"><input type="hidden" name="action" value="promote"><button class="btn ghost" type="submit">Confirm &amp; file in cabinet</button></form>
</div>
<?php if ($doc['review_status'] === 'inbox'): ?>
    <p class="lede">This is still in the inbox. Save a type/title here, or tell me to catalog it from the scan.</p>
<?php endif; ?>
<?php
render_footer();

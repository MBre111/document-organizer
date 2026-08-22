<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/facts_ui.php';

$id = (int) ($_GET['id'] ?? 0);
$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM entities WHERE id = ?');
$stmt->execute([$id]);
$ent = $stmt->fetch();
if (!$ent) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$extra = extra_decode($ent['extra_json'] ?? null);

$docs = $pdo->prepare(
    'SELECT d.id, d.title, d.doc_type, d.source_org, d.doc_date, d.review_status, d.summary, de.role
     FROM document_entities de
     JOIN documents d ON d.id = de.document_id
     WHERE de.entity_id = ? AND d.review_status NOT IN (\'out_of_scope\')
     ORDER BY COALESCE(d.doc_date, d.created_at) DESC, d.id DESC'
);
$docs->execute([$id]);
$docs = $docs->fetchAll();

$docIds = array_values(array_unique(array_map(static fn ($d) => (int) $d['id'], $docs)));
$facts = [];
if ($docIds) {
    $in = implode(',', array_fill(0, count($docIds), '?'));
    $fst = $pdo->prepare(
        "SELECT u.*, d.title AS doc_title FROM untrusted_facts u
         LEFT JOIN documents d ON d.id = u.document_id
         WHERE u.status = 'open' AND u.document_id IN ($in)
         ORDER BY FIELD(u.importance,'important','normal'), u.id"
    );
    $fst->execute($docIds);
    $facts = $fst->fetchAll();
}

$caseRow = null;
if (($ent['kind'] ?? '') === 'case' && table_exists($pdo, 'cases')) {
    $c = $pdo->prepare('SELECT * FROM cases WHERE case_number = ? LIMIT 1');
    $c->execute([(string) $ent['name']]);
    $caseRow = $c->fetch() ?: null;
}

render_header($ent['name'] ?: 'Entity', 'library');
?>
<p class="back"><a href="index.php?view=library">← Library</a></p>
<p class="meta"><?= h((string) $ent['kind']) ?></p>
<h1><?= h((string) $ent['name']) ?></h1>
<?php if (!empty($ent['notes'])): ?>
    <p><?= nl2br(h((string) $ent['notes'])) ?></p>
<?php endif; ?>
<?php if ($caseRow): ?>
    <p><a class="btn small" href="case.php?id=<?= (int) $caseRow['id'] ?>">Open case timeline</a></p>
<?php endif; ?>
<?php if ($extra): ?>
    <dl class="extra">
        <?php foreach ($extra as $k => $v): ?>
            <dt><?= h((string) $k) ?></dt>
            <dd><?= h(is_scalar($v) ? (string) $v : json_encode($v)) ?></dd>
        <?php endforeach; ?>
    </dl>
<?php endif; ?>

<h2>Documents</h2>
<?php if (!$docs): ?>
    <p class="muted">Nothing linked yet.</p>
<?php else: ?>
    <ul class="docs">
        <?php foreach ($docs as $row): ?>
            <li>
                <a href="document.php?id=<?= (int) $row['id'] ?>">
                    <strong><?= h($row['title'] ?: 'Untitled') ?></strong>
                    <span class="meta">
                        <?= h((string) $row['role']) ?>
                        <?= $row['doc_date'] ? ' · ' . h((string) $row['doc_date']) : '' ?>
                        <?= $row['doc_type'] ? ' · ' . h((string) $row['doc_type']) : '' ?>
                    </span>
                    <?php if (!empty($row['summary'])): ?>
                        <span class="sum"><?= h((string) $row['summary']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<p class="muted"><a href="index.php?view=library&amp;entity=<?= (int) $id ?>">Filter library to this connection</a></p>

<?php if ($facts): ?>
    <section class="untrusted">
        <h2>Needs confirming</h2>
        <ul class="facts">
            <?php foreach ($facts as $fact): ?>
                <?php render_fact_card($fact, 'entity.php?id=' . $id); ?>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
<?php
render_footer();

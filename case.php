<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/facts_ui.php';

$id = (int) ($_GET['id'] ?? 0);
$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM cases WHERE id = ?');
$stmt->execute([$id]);
$case = $stmt->fetch();
if (!$case) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$docs = $pdo->prepare(
    'SELECT id, title, doc_type, source_org, doc_date, review_status, summary
     FROM documents
     WHERE case_id = ? AND review_status NOT IN (\'out_of_scope\')
     ORDER BY COALESCE(doc_date, created_at) ASC, id ASC'
);
$docs->execute([$id]);
$docs = $docs->fetchAll();

$parties = $pdo->prepare(
    "SELECT DISTINCT e.id, e.kind, e.name, de.role
     FROM document_entities de
     JOIN entities e ON e.id = de.entity_id
     JOIN documents d ON d.id = de.document_id
     WHERE d.case_id = ? AND de.role IN ('plaintiff','defendant','landlord','property','issuer')
     ORDER BY de.role, e.name"
);
$parties->execute([$id]);
$parties = $parties->fetchAll();

$docIds = array_map(static fn ($d) => (int) $d['id'], $docs);
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

$deadlines = [];
if (table_exists($pdo, 'deadlines')) {
    $dl = $pdo->prepare(
        "SELECT * FROM deadlines WHERE case_id = ? AND status = 'open' ORDER BY due_on"
    );
    $dl->execute([$id]);
    $deadlines = $dl->fetchAll();
}

render_header($case['case_number'] ?: 'Case', 'library');
?>
<p class="back"><a href="index.php?view=library">← Library</a></p>
<p class="meta"><?= h((string) $case['status']) ?> · case</p>
<h1><?= h((string) $case['case_number']) ?></h1>
<?php if (!empty($case['title'])): ?>
    <p class="lede"><?= h((string) $case['title']) ?></p>
<?php endif; ?>
<?php if (!empty($case['court'])): ?>
    <p class="muted"><?= h((string) $case['court']) ?></p>
<?php endif; ?>
<p><a class="btn" href="packet.php?id=<?= (int) $id ?>">Download case packet (zip)</a></p>

<?php if ($parties): ?>
    <h2>Parties</h2>
    <ul>
        <?php foreach ($parties as $p): ?>
            <li><a href="entity.php?id=<?= (int) $p['id'] ?>"><?= h($p['name']) ?></a>
                <span class="muted">(<?= h($p['role']) ?>)</span></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($deadlines): ?>
    <h2>Dates</h2>
    <ul class="coming-up-list">
        <?php foreach ($deadlines as $row): ?>
            <li><?= h((string) $row['due_on']) ?> · <?= h((string) $row['title']) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h2>Timeline</h2>
<?php if (!$docs): ?>
    <p class="muted">No documents linked yet.</p>
<?php else: ?>
    <ul class="docs">
        <?php foreach ($docs as $row): ?>
            <li>
                <a href="document.php?id=<?= (int) $row['id'] ?>">
                    <strong><?= h($row['title'] ?: 'Untitled') ?></strong>
                    <span class="meta">
                        <?= h((string) ($row['doc_date'] ?? '')) ?>
                        <?= $row['doc_type'] ? ' · ' . h($row['doc_type']) : '' ?>
                        · <?= h((string) $row['review_status']) ?>
                    </span>
                    <?php if (!empty($row['summary'])): ?>
                        <span class="sum"><?= h((string) $row['summary']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<p class="muted"><a href="index.php?view=library&amp;case=<?= (int) $id ?>">Search this case in the library</a></p>

<?php if ($facts): ?>
    <section class="untrusted">
        <h2>Needs confirming</h2>
        <ul class="facts">
            <?php foreach ($facts as $fact): ?>
                <?php render_fact_card($fact, 'case.php?id=' . $id); ?>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>
<?php
render_footer();

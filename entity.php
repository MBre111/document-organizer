<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/facts_ui.php';

$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

if ($id < 1) {
    $groups = [];
    if (table_exists($pdo, 'entities')) {
        $rows = $pdo->query('SELECT id, kind, name FROM entities ORDER BY kind, name')->fetchAll();
        foreach ($rows as $row) {
            $groups[(string) $row['kind']][] = $row;
        }
    }
    render_header('Wiki', 'library');
    echo '<p class="back"><a href="index.php">← Today</a></p>';
    echo '<h1>Wiki</h1>';
    echo '<p class="lede">People, places, and things that journals and documents keep linking back to.</p>';
    if (!$groups) {
        echo '<p class="muted">Nothing here yet.</p>';
        render_footer();
        exit;
    }
    foreach ($groups as $kind => $rows) {
        echo '<h2>' . h($kind) . '</h2><ul class="docs">';
        foreach ($rows as $row) {
            echo '<li><a href="entity.php?id=' . (int) $row['id'] . '"><strong>' . h((string) $row['name']) . '</strong></a></li>';
        }
        echo '</ul>';
    }
    render_footer();
    exit;
}

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

$journals = [];
if (table_exists($pdo, 'journal_entities')) {
    $js = $pdo->prepare(
        'SELECT j.id, j.entry_date, j.title, LEFT(j.body, 180) AS preview, je.role
         FROM journal_entities je
         JOIN journals j ON j.id = je.journal_id
         WHERE je.entity_id = ?
         ORDER BY j.entry_date DESC, j.id DESC'
    );
    $js->execute([$id]);
    $journals = $js->fetchAll();
}

$tasks = [];
if (table_exists($pdo, 'deadlines')) {
    $tq = $pdo->prepare(
        "SELECT DISTINCT dl.id, dl.due_on, dl.kind, dl.title, dl.status
         FROM deadlines dl
         LEFT JOIN journal_entities je ON je.journal_id = dl.journal_id
         LEFT JOIN document_entities de ON de.document_id = dl.document_id
         WHERE dl.entity_id = ? OR je.entity_id = ? OR de.entity_id = ?
         ORDER BY (dl.status = 'open') DESC, dl.due_on ASC
         LIMIT 20"
    );
    $tq->execute([$id, $id, $id]);
    $tasks = $tq->fetchAll();
}

$meas = [];
if (table_exists($pdo, 'measurements') && ($ent['kind'] ?? '') === 'person' && $ent['name'] === 'Michael David Bredin') {
    $meas = $pdo->query(
        "SELECT taken_on, value_num, unit, conditions FROM measurements WHERE kind = 'weight' ORDER BY taken_on DESC LIMIT 12"
    )->fetchAll();
}

render_header($ent['name'] ?: 'Wiki', 'library');
?>
<p class="back"><a href="entity.php">← Wiki</a></p>
<p class="page-meta-row"><span class="pill"><?= h((string) $ent['kind']) ?></span></p>
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

<?php if ($tasks): ?>
    <h2>Tasks</h2>
    <ul class="docs">
        <?php foreach ($tasks as $row): ?>
            <li>
                <a href="deadline.php?id=<?= (int) $row['id'] ?>">
                    <strong><?= h((string) $row['title']) ?></strong>
                    <span class="meta"><?= h((string) $row['due_on']) ?> · <?= h(kind_label((string) $row['kind'])) ?> · <?= h((string) $row['status']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($meas): ?>
    <h2>Weight</h2>
    <ul>
        <?php foreach ($meas as $m): ?>
            <li><?= h((string) $m['taken_on']) ?> · <strong><?= h((string) $m['value_num']) ?> <?= h((string) $m['unit']) ?></strong>
                <?php if ($m['conditions']): ?><span class="muted"> · <?= h((string) $m['conditions']) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($journals): ?>
    <h2>Journal</h2>
    <ul class="docs">
        <?php foreach ($journals as $j): ?>
            <li>
                <a href="journal.php?id=<?= (int) $j['id'] ?>">
                    <strong><?= h((string) ($j['title'] ?: 'Journal')) ?></strong>
                    <span class="meta"><?= h((string) $j['entry_date']) ?><?= $j['role'] ? ' · ' . h((string) $j['role']) : '' ?></span>
                    <span class="sum"><?= h((string) $j['preview']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php else: ?>
    <h2>Journal</h2>
    <p class="muted">No journal lines linked yet. New entries that name this page will show up here.</p>
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

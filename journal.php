<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $body = trim((string) ($_POST['body'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $date = trim((string) ($_POST['entry_date'] ?? date('Y-m-d')));
    if ($body !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $jid = save_journal_entry($pdo, $date, $body, $title, 'typed');
        header('Location: journal.php?id=' . $jid, true, 303);
        exit;
    }
}

$id = (int) ($_GET['id'] ?? 0);
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM journals WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    $ents = $pdo->prepare(
        'SELECT e.id, e.kind, e.name, je.role
         FROM journal_entities je
         JOIN entities e ON e.id = je.entity_id
         WHERE je.journal_id = ?
         ORDER BY e.kind, e.name'
    );
    $ents->execute([$id]);
    $ents = $ents->fetchAll();
    $meas = $pdo->prepare(
        'SELECT * FROM measurements WHERE journal_id = ? ORDER BY kind, taken_on'
    );
    $meas->execute([$id]);
    $meas = $meas->fetchAll();

    render_header($row['title'] ?: ('Journal ' . $row['entry_date']), 'journal');
    echo '<p class="back"><a href="journal.php">← Journals</a></p>';
    echo '<p class="meta">' . h((string) $row['entry_date']);
    if (!empty($row['entry_at'])) {
        echo ' · ' . h(substr((string) $row['entry_at'], 11, 5));
    }
    echo ' · ' . h((string) $row['source']) . '</p>';
    echo '<h1>' . h((string) ($row['title'] ?: 'Journal')) . '</h1>';
    echo '<p>' . nl2br(h((string) $row['body'])) . '</p>';
    if ($meas) {
        echo '<h2>Logged from this entry</h2><ul>';
        foreach ($meas as $m) {
            echo '<li>' . h($m['kind']) . ': ' . h((string) $m['value_num']) . ' ' . h((string) $m['unit']);
            if ($m['conditions']) {
                echo ' <span class="muted">(' . h((string) $m['conditions']) . ')</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }
    if ($ents) {
        echo '<h2>Wiki</h2><ul>';
        foreach ($ents as $e) {
            echo '<li><a href="entity.php?id=' . (int) $e['id'] . '">' . h($e['name']) . '</a>';
            echo ' <span class="muted">(' . h($e['kind']);
            if ($e['role']) {
                echo ' · ' . h($e['role']);
            }
            echo ')</span></li>';
        }
        echo '</ul>';
    }
    if (table_exists($pdo, 'untrusted_facts') && column_exists($pdo, 'untrusted_facts', 'journal_id')) {
        require_once __DIR__ . '/includes/facts_ui.php';
        $jf = $pdo->prepare(
            "SELECT u.*, j.title AS journal_title FROM untrusted_facts u
             LEFT JOIN journals j ON j.id = u.journal_id
             WHERE u.journal_id = ? AND u.status = 'open' ORDER BY u.id"
        );
        $jf->execute([$id]);
        $jf = $jf->fetchAll();
        if ($jf) {
            echo '<h2>Tasks from this entry</h2><ul class="facts">';
            foreach ($jf as $fact) {
                render_fact_card($fact, 'journal.php?id=' . $id);
            }
            echo '</ul>';
        }
    }
    $dls = $pdo->prepare("SELECT * FROM deadlines WHERE journal_id = ? ORDER BY due_on");
    $dls->execute([$id]);
    $dls = $dls->fetchAll();
    if ($dls) {
        echo '<h2>Tasks added</h2><ul>';
        foreach ($dls as $dl) {
            echo '<li><a href="deadline.php?id=' . (int) $dl['id'] . '">' . h((string) $dl['title']) . '</a>';
            echo ' <span class="muted">' . h((string) $dl['due_on']) . ' · ' . h((string) $dl['status']) . '</span></li>';
        }
        echo '</ul>';
    }
    render_footer();
    exit;
}

$list = $pdo->query('SELECT id, entry_date, title, LEFT(body, 180) AS preview FROM journals ORDER BY entry_date DESC, id DESC')->fetchAll();
render_header('Journal', 'journal');
?>
<h1>Journal</h1>
<p class="lede">Write or paste today. Dates become tasks to tap.</p>
<form method="post" class="edit-doc">
    <label>Date <input type="date" name="entry_date" value="<?= h(date('Y-m-d')) ?>"></label>
    <label>Title (optional) <input name="title" placeholder="Short label"></label>
    <label>Entry <textarea name="body" rows="8" placeholder="Paste or type today’s dictation"></textarea></label>
    <button class="btn" type="submit">Save entry</button>
</form>
<?php if (!$list): ?>
    <p class="muted">No entries yet.</p>
<?php else: ?>
    <ul class="docs">
        <?php foreach ($list as $row): ?>
            <li>
                <a href="journal.php?id=<?= (int) $row['id'] ?>">
                    <strong><?= h((string) ($row['title'] ?: 'Journal')) ?></strong>
                    <span class="meta"><?= h((string) $row['entry_date']) ?></span>
                    <span class="sum"><?= h((string) $row['preview']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php
render_footer();

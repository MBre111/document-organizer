<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$view = (string) ($_GET['view'] ?? 'today');
if (!in_array($view, ['today', 'inbox', 'library'], true)) {
    $view = 'today';
}

try {
    $pdo = db();
    require_once __DIR__ . '/includes/facts_ui.php';
    handle_today_post($pdo);
    handle_fact_post($pdo);
} catch (PDOException $e) {
    render_header('Setup', $view);
    echo '<h1>Database not ready</h1>';
    echo '<p>Start Wamp until the icon is green, then open <a href="install.php">install.php</a> once.</p>';
    echo '<p class="muted">' . h($e->getMessage()) . '</p>';
    render_footer();
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));
$tag = trim((string) ($_GET['tag'] ?? ''));
$entityId = (int) ($_GET['entity'] ?? 0);
$caseId = (int) ($_GET['case'] ?? 0);
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

if ($view === 'today') {
    $inboxN = (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE review_status = 'inbox'")->fetchColumn();
    $factN = (int) $pdo->query("SELECT COUNT(*) FROM untrusted_facts WHERE status = 'open'")->fetchColumn();
    $inboxRows = $pdo->query(
        "SELECT id, title, created_at FROM documents WHERE review_status = 'inbox' ORDER BY created_at DESC LIMIT 3"
    )->fetchAll();
    $factRows = $pdo->query(
        "SELECT u.*, d.title AS doc_title, j.title AS journal_title, j.entry_date
         FROM untrusted_facts u
         LEFT JOIN documents d ON d.id = u.document_id
         LEFT JOIN journals j ON j.id = u.journal_id
         WHERE u.status = 'open'
         ORDER BY (u.fact_key IN ('proposed_task','bill_match') OR u.journal_id IS NOT NULL) DESC, FIELD(u.importance,'important','normal'), u.id
         LIMIT 2"
    )->fetchAll();
    $moneyLeft = null;
    if (table_exists($pdo, 'budget_lines')) {
        $ym = date('Y-m');
        ensure_budget_month($pdo, $ym);
        $tot = budget_totals(budget_categories($pdo), budget_rollups($pdo, $ym));
        $moneyLeft = $tot['planned'] > 0 ? $tot['left'] : null;
    }
    render_header('Today', 'today');
    echo '<h1>' . h(date('l, M j')) . '</h1>';
    echo '<div class="chips">';
    echo '<a class="chip" href="index.php?view=inbox"><span>Inbox</span><b>' . $inboxN . '</b></a>';
    echo '<a class="chip' . ($factN ? ' warn' : '') . '" href="facts.php"><span>Confirm</span><b>' . $factN . '</b></a>';
    echo '<a class="chip" href="money.php"><span>Budget left</span><b>';
    echo $moneyLeft === null ? 'Set' : h(money_plain($moneyLeft));
    echo '</b></a></div>';
    render_deadline_strip($pdo);
    if ($factRows) {
        require_once __DIR__ . '/includes/facts_ui.php';
        echo '<h2>Tap these</h2>';
        echo '<ul class="facts">';
        foreach ($factRows as $fact) {
            render_fact_card($fact, 'index.php');
        }
        echo '</ul>';
        if ($factN > 2) {
            echo '<p class="muted"><a href="facts.php">All ' . $factN . ' to confirm</a></p>';
        }
    }
    render_morning_log($pdo);
    render_money_strip($pdo);
    if (table_exists($pdo, 'journals')) {
        $latestJ = $pdo->query('SELECT id, entry_date, title, LEFT(body, 180) AS preview FROM journals ORDER BY entry_date DESC, id DESC LIMIT 1')->fetch();
        if ($latestJ) {
            echo '<h2>Journal</h2><ul class="docs"><li>';
            echo '<a href="journal.php?id=' . (int) $latestJ['id'] . '"><strong>' . h((string) ($latestJ['title'] ?: 'Journal')) . '</strong>';
            echo '<span class="meta">' . h((string) $latestJ['entry_date']) . '</span>';
            echo '<span class="sum">' . h((string) $latestJ['preview']) . '</span></a></li></ul>';
        }
    }
    if ($inboxRows) {
        echo '<h2>Waiting in inbox</h2><ul class="docs">';
        foreach ($inboxRows as $row) {
            echo '<li><a href="document.php?id=' . (int) $row['id'] . '"><strong>' . h($row['title'] ?: 'Untitled') . '</strong>';
            echo '<span class="meta">' . h(substr((string) $row['created_at'], 0, 16)) . '</span></a></li>';
        }
        echo '</ul>';
    }
    render_footer();
    exit;
}

if ($view === 'inbox') {
    $rows = $pdo->query(
        "SELECT d.id, d.title, d.notes, d.review_status, d.created_at,
                COUNT(f.id) AS page_count
         FROM documents d
         LEFT JOIN files f ON f.document_id = d.id
         WHERE d.review_status = 'inbox'
         GROUP BY d.id
         ORDER BY d.created_at DESC"
    )->fetchAll();
} else {
    $sql = "SELECT DISTINCT d.id, d.title, d.doc_type, d.source_org, d.doc_date, d.review_status, d.summary, d.case_id, c.case_number
            FROM documents d
            LEFT JOIN cases c ON c.id = d.case_id
            LEFT JOIN document_tags dt ON dt.document_id = d.id
            LEFT JOIN tags t ON t.id = dt.tag_id
            LEFT JOIN document_entities de ON de.document_id = d.id
            LEFT JOIN entities e ON e.id = de.entity_id
            LEFT JOIN files f ON f.document_id = d.id
            WHERE 1=1";
    $params = [];
    if ($status === 'all') {
        // include everything except we still skip junk? plan: hide out_of_scope unless status=all
    } elseif ($status === 'extracted' || $status === 'confirmed') {
        $sql .= ' AND d.review_status = ?';
        $params[] = $status;
    } else {
        $sql .= " AND d.review_status NOT IN ('inbox', 'out_of_scope')";
    }
    if ($type !== '') {
        $sql .= ' AND d.doc_type = ?';
        $params[] = $type;
    }
    if ($tag !== '') {
        $sql .= ' AND t.name = ?';
        $params[] = $tag;
    }
    if ($entityId > 0) {
        $sql .= ' AND de.entity_id = ?';
        $params[] = $entityId;
    }
    if ($caseId > 0) {
        $sql .= ' AND d.case_id = ?';
        $params[] = $caseId;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $sql .= ' AND d.doc_date >= ?';
        $params[] = $from;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $sql .= ' AND d.doc_date <= ?';
        $params[] = $to;
    }
    if ($q !== '') {
        $like = '%' . $q . '%';
        $sql .= ' AND (
            d.title LIKE ? OR d.summary LIKE ? OR d.source_org LIKE ? OR d.notes LIKE ?
            OR CAST(d.extra_json AS CHAR) LIKE ? OR t.name LIKE ? OR e.name LIKE ?
            OR c.case_number LIKE ? OR f.ocr_text LIKE ?
        )';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY COALESCE(d.doc_date, d.created_at) DESC, d.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$types = $pdo->query(
    "SELECT DISTINCT doc_type FROM documents WHERE doc_type IS NOT NULL AND doc_type <> '' ORDER BY doc_type"
)->fetchAll(PDO::FETCH_COLUMN);

render_header($view === 'inbox' ? 'Inbox' : 'Library', $view);
?>
<h1><?= $view === 'inbox' ? 'Inbox' : 'Library' ?></h1>
<?php render_deadline_strip($pdo); ?>
<?php if ($view === 'inbox'): ?>
    <p class="lede"><?= count($rows) ?> waiting. <a href="upload.php">Add a scan</a>, then ask me to catalog.</p>
    <p><a class="btn" href="upload.php">Upload</a></p>
<?php else: ?>
    <form class="search search-full" method="get">
        <input type="hidden" name="view" value="library">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search 6082, Olivia, Celtic, water…">
        <button type="submit">Search</button>
        <select name="type" onchange="this.form.submit()">
            <option value="">All types</option>
            <?php foreach ($types as $t): ?>
                <option value="<?= h((string) $t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= h((string) $t) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()">
            <option value="" <?= $status === '' ? 'selected' : '' ?>>Extracted + confirmed</option>
            <option value="extracted" <?= $status === 'extracted' ? 'selected' : '' ?>>Extracted</option>
            <option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
            <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
        </select>
        <input type="date" name="from" value="<?= h($from) ?>" title="From date">
        <input type="date" name="to" value="<?= h($to) ?>" title="To date">
        <?php if ($entityId): ?><input type="hidden" name="entity" value="<?= $entityId ?>"><?php endif; ?>
        <?php if ($caseId): ?><input type="hidden" name="case" value="<?= $caseId ?>"><?php endif; ?>
        <?php if ($tag !== ''): ?><input type="hidden" name="tag" value="<?= h($tag) ?>"><?php endif; ?>
    </form>
    <?php if ($entityId || $caseId || $tag !== '' || $type !== '' || $q !== ''): ?>
        <p class="muted">
            Filtered
            <?php if ($q !== ''): ?> · “<?= h($q) ?>”<?php endif; ?>
            <?php if ($tag !== ''): ?> · tag <?= h($tag) ?><?php endif; ?>
            <a href="index.php?view=library">Clear</a>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php if (!$rows): ?>
    <p class="muted">Nothing here yet.</p>
<?php else: ?>
    <ul class="docs">
        <?php foreach ($rows as $row): ?>
            <li>
                <a href="document.php?id=<?= (int) $row['id'] ?>">
                    <strong><?= h($row['title'] ?: ($row['original_filename'] ?? 'Untitled')) ?></strong>
                    <span class="meta">
                        <?php if ($view === 'inbox'): ?>
                            <span class="pages-pill"><?= (int) $row['page_count'] ?> page<?= ((int) $row['page_count']) === 1 ? '' : 's' ?></span>
                            · <?= h(substr((string) $row['created_at'], 0, 16)) ?>
                        <?php else: ?>
                            <?= h($row['doc_type'] ?? '') ?>
                            <?= $row['source_org'] ? ' · ' . h($row['source_org']) : '' ?>
                            <?= $row['doc_date'] ? ' · ' . h($row['doc_date']) : '' ?>
                            <?php if (!empty($row['case_number'])): ?>
                                · <?= h((string) $row['case_number']) ?>
                            <?php endif; ?>
                            · <?= h($row['review_status']) ?>
                        <?php endif; ?>
                    </span>
                    <?php if (!empty($row['notes']) && $view === 'inbox'): ?>
                        <span class="sum"><?= h($row['notes']) ?></span>
                    <?php elseif (!empty($row['summary'])): ?>
                        <span class="sum"><?= h($row['summary']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php
render_footer();

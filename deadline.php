<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$pdo = db();
handle_deadline_post($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: index.php', true, 303);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT dl.*, d.title AS doc_title, c.case_number, j.title AS journal_title, j.entry_date
     FROM deadlines dl
     LEFT JOIN documents d ON d.id = dl.document_id
     LEFT JOIN cases c ON c.id = dl.case_id
     LEFT JOIN journals j ON j.id = dl.journal_id
     WHERE dl.id = ?'
);
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$items = deadline_items($pdo, $id);
$today = date('Y-m-d');
$overdue = ($row['due_on'] ?? '') < $today && ($row['status'] ?? '') === 'open';

render_header((string) $row['title'], 'today');
echo '<p class="back"><a href="index.php">← Today</a></p>';
echo '<h1>' . h((string) $row['title']) . '</h1>';
echo '<p class="meta">' . h((string) $row['kind']) . ' · due ' . h((string) $row['due_on']);
if ($overdue) {
    echo ' · <span class="when">overdue</span>';
}
echo ' · ' . h((string) $row['status']) . '</p>';

if (!empty($row['document_id'])) {
    echo '<p><a href="document.php?id=' . (int) $row['document_id'] . '">'
        . h((string) ($row['doc_title'] ?: 'Document')) . '</a></p>';
}
if (!empty($row['case_id'])) {
    echo '<p><a href="case.php?id=' . (int) $row['case_id'] . '">Case '
        . h((string) ($row['case_number'] ?: $row['case_id'])) . '</a></p>';
}
if (!empty($row['journal_id'])) {
    echo '<p><a href="journal.php?id=' . (int) $row['journal_id'] . '">Journal '
        . h((string) ($row['journal_title'] ?: $row['entry_date'])) . '</a></p>';
}

if (($row['status'] ?? '') === 'open') {
    echo '<form method="post" class="dl-actions">';
    echo '<input type="hidden" name="id" value="' . $id . '">';
    echo '<input type="hidden" name="return" value="deadline.php?id=' . $id . '">';
    echo '<button class="btn" name="action" value="done" type="submit">Done</button>';
    echo '<button class="btn ghost" name="action" value="cancelled" type="submit">Not this</button>';
    echo '</form>';
}

echo '<h2>Checklist</h2>';
if ($items) {
    echo '<ul class="check-list">';
    foreach ($items as $item) {
        $done = ($item['status'] ?? '') === 'done';
        echo '<li class="' . ($done ? 'done' : '') . '">';
        echo '<form method="post" action="deadline.php">';
        echo '<input type="hidden" name="item_id" value="' . (int) $item['id'] . '">';
        echo '<input type="hidden" name="return" value="deadline.php?id=' . $id . '">';
        echo '<button class="choice" name="action" value="' . ($done ? 'item_open' : 'item_done') . '" type="submit">';
        echo $done ? '✓ ' : '○ ';
        echo h((string) $item['title']);
        echo '</button></form></li>';
    }
    echo '</ul>';
} else {
    echo '<p class="muted">No steps yet.</p>';
}

echo '<form method="post" class="edit-doc">';
echo '<input type="hidden" name="action" value="add_item">';
echo '<input type="hidden" name="id" value="' . $id . '">';
echo '<input type="hidden" name="return" value="deadline.php?id=' . $id . '">';
echo '<label>Add a step <input name="item_title" placeholder="Box the kitchen stuff" required></label>';
echo '<button class="btn" type="submit">Add</button>';
echo '</form>';

render_footer();

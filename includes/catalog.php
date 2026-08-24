<?php

declare(strict_types=1);

function extra_decode(?string $raw): array
{
    if ($raw === null || $raw === '') {
        return [];
    }
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function extra_encode(array $extra): string
{
    return json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function fact_is_non_answer(string $value): bool
{
    $v = trim($value);
    $needles = [
        "can't read", 'cannot read', "can't tell", 'cannot tell',
        "don't know", 'do not know', 'do not treat', 'not needed',
        'junk', 'ignore this', 'ignore /', 'no docket', 'not sure',
        'leave it blank', "i'll add", 'draft only / not served',
        'skip', 'skip this', 'skip this task',
    ];
    $lower = strtolower($v);
    foreach ($needles as $n) {
        if (str_contains($lower, $n)) {
            return true;
        }
    }
    return false;
}

function parse_fact_date(string $value): ?string
{
    $v = trim($value);
    if (preg_match('/\b(20\d{2})-(\d{2})-(\d{2})\b/', $v, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/', $v, $m)) {
        $y = (int) $m[3];
        if ($y < 100) {
            $y += 2000;
        }
        return sprintf('%04d-%02d-%02d', $y, (int) $m[1], (int) $m[2]);
    }
    $ts = strtotime($v);
    if ($ts !== false) {
        $prefix = substr($v, 0, 40);
        if (preg_match('/\d{4}|\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\b/i', $prefix)) {
            return date('Y-m-d', $ts);
        }
    }
    if (preg_match('/\b(20\d{2})\b/', $v, $m) && !preg_match('/\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec|[0-9]{1,2}\/)/i', $v)) {
        return null;
    }
    return null;
}

function parse_fact_year(string $value): ?string
{
    if (preg_match('/\b(20\d{2})\b/', $value, $m)) {
        return $m[1];
    }
    return null;
}

function parse_fact_number(string $value): ?float
{
    if (preg_match('/\$?\s*([0-9]{1,3}(?:,[0-9]{3})*(?:\.[0-9]+)?|[0-9]+(?:\.[0-9]+)?)/', $value, $m)) {
        return (float) str_replace(',', '', $m[1]);
    }
    return null;
}

function parse_docket(string $value): ?string
{
    if (preg_match('/\b\d{4}-?[A-Z]{2}-?\d{3,6}-?[A-Z]{0,3}\b/i', $value, $m)) {
        return strtoupper($m[0]);
    }
    if (preg_match('/\b\d{4}[A-Z]{2}\d{3,6}[A-Z]{0,3}\b/i', $value, $m)) {
        return strtoupper($m[0]);
    }
    return null;
}

/** @return array{extra:string, parse:string, doc_date?:bool, deadline?:string} */
function fact_writer(string $key): array
{
    $map = [
        'lease_start' => ['extra' => 'start_date', 'parse' => 'date', 'deadline' => 'lease_start'],
        'lease_end' => ['extra' => 'end_date', 'parse' => 'date', 'deadline' => 'lease_end'],
        'due_date' => ['extra' => 'due_date', 'parse' => 'date', 'deadline' => 'bill_due'],
        'bill_date' => ['extra' => 'bill_date', 'parse' => 'date', 'doc_date' => true],
        'letter_date' => ['extra' => 'letter_date', 'parse' => 'date', 'doc_date' => true],
        'text_date_year' => ['extra' => 'text_date', 'parse' => 'date'],
        'year' => ['extra' => 'todo_year', 'parse' => 'year'],
        'case_number' => ['extra' => 'case_number', 'parse' => 'docket'],
        'case_number_format' => ['extra' => 'case_number', 'parse' => 'docket'],
        'served_on' => ['extra' => 'served_on', 'parse' => 'text'],
        'damages_total' => ['extra' => 'damages_total', 'parse' => 'number'],
        'vehicle_make_model' => ['extra' => 'vehicle_make_model', 'parse' => 'text'],
        'extra_occupant_demand' => ['extra' => 'extra_occupant_demand', 'parse' => 'text'],
        'executed' => ['extra' => 'executed', 'parse' => 'text'],
        'document_subject' => ['extra' => 'record_kind', 'parse' => 'text'],
        'danny_eviction_hold' => ['extra' => 'danny', 'parse' => 'text'],
        'listing_currency_mixed' => ['extra' => 'listing_mix', 'parse' => 'text'],
        'was_filed' => ['extra' => 'was_filed', 'parse' => 'text'],
        'medication_list' => ['extra' => 'medication_list', 'parse' => 'text'],
        'olivia_alternative_cause' => ['extra' => 'olivia_alternative_cause', 'parse' => 'text'],
        'case_details' => ['extra' => 'case_details', 'parse' => 'text'],
        'amount_and_parties' => ['extra' => 'note_executed', 'parse' => 'text'],
    ];
    return $map[$key] ?? ['extra' => $key, 'parse' => 'text'];
}

function write_confirmed_fact(PDO $pdo, array $fact, string $chosen): void
{
    $key = (string) ($fact['fact_key'] ?? '');
    if ($key === 'proposed_task') {
        if (!fact_is_non_answer($chosen)) {
            confirm_proposed_task($pdo, $fact, $chosen);
        }
        return;
    }
    $docId = (int) ($fact['document_id'] ?? 0);
    if ($docId < 1 || fact_is_non_answer($chosen)) {
        return;
    }
    if ($key === '') {
        return;
    }
    $writer = fact_writer($key);
    $parsed = $chosen;
    if ($writer['parse'] === 'date') {
        $d = parse_fact_date($chosen);
        if ($d === null) {
            $parsed = $chosen;
        } else {
            $parsed = $d;
        }
    } elseif ($writer['parse'] === 'year') {
        $parsed = parse_fact_year($chosen) ?? $chosen;
    } elseif ($writer['parse'] === 'number') {
        $n = parse_fact_number($chosen);
        $parsed = $n === null ? $chosen : $n;
    } elseif ($writer['parse'] === 'docket') {
        $parsed = parse_docket($chosen) ?? $chosen;
    }

    $stmt = $pdo->prepare('SELECT extra_json, doc_date, case_id FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        return;
    }
    $extra = extra_decode($doc['extra_json'] ?? null);
    $extra[$writer['extra']] = $parsed;
    $sets = ['extra_json' => extra_encode($extra)];
    if (!empty($writer['doc_date']) && is_string($parsed) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsed)) {
        $sets['doc_date'] = $parsed;
    }
    $sql = 'UPDATE documents SET extra_json = :extra_json';
    $params = ['extra_json' => $sets['extra_json'], 'id' => $docId];
    if (isset($sets['doc_date'])) {
        $sql .= ', doc_date = :doc_date';
        $params['doc_date'] = $sets['doc_date'];
    }
    $sql .= ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    if (!empty($writer['deadline']) && is_string($parsed) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parsed)) {
        upsert_deadline($pdo, $docId, $writer['deadline'], $writer['extra'], $parsed, null, $chosen);
    }
    if ($writer['extra'] === 'case_number' && is_string($parsed)) {
        $caseId = find_or_create_case_id($pdo, $parsed);
        if ($caseId) {
            $pdo->prepare('UPDATE documents SET case_id = ? WHERE id = ? AND (case_id IS NULL OR case_id = 0)')
                ->execute([$caseId, $docId]);
        }
    }
}

function maybe_confirm_document(PDO $pdo, int $docId): void
{
    if ($docId < 1) {
        return;
    }
    $open = $pdo->prepare('SELECT COUNT(*) FROM untrusted_facts WHERE document_id = ? AND status = ?');
    $open->execute([$docId, 'open']);
    if ((int) $open->fetchColumn() > 0) {
        return;
    }
    $pdo->prepare("UPDATE documents SET review_status = 'confirmed' WHERE id = ? AND review_status = 'extracted'")
        ->execute([$docId]);
    $check = $pdo->prepare('SELECT review_status FROM documents WHERE id = ?');
    $check->execute([$docId]);
    if ($check->fetchColumn() === 'confirmed') {
        materialize_document_google($pdo, $docId);
        promote_document_files($pdo, $docId);
    }
}

function table_exists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$name]);
    return (bool) $stmt->fetch();
}

function upsert_deadline(
    PDO $pdo,
    ?int $documentId,
    string $kind,
    string $sourceKey,
    string $dueOn,
    ?string $dueAt,
    string $title
): void {
    if (!table_exists($pdo, 'deadlines')) {
        return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueOn)) {
        return;
    }
    $caseId = null;
    if ($documentId) {
        $s = $pdo->prepare('SELECT case_id FROM documents WHERE id = ?');
        $s->execute([$documentId]);
        $caseId = $s->fetchColumn() ?: null;
        $caseId = $caseId ? (int) $caseId : null;
    }
    $title = mb_substr(trim($title) !== '' ? trim($title) : ($kind . ' ' . $dueOn), 0, 180);
    if ($documentId) {
        $find = $pdo->prepare('SELECT id FROM deadlines WHERE document_id = ? AND source_key = ? LIMIT 1');
        $find->execute([$documentId, $sourceKey]);
        $id = $find->fetchColumn();
        if ($id) {
            $pdo->prepare(
                'UPDATE deadlines SET due_on=?, due_at=?, kind=?, title=?, case_id=?, status=CASE WHEN status=\'cancelled\' THEN status ELSE \'open\' END
                 WHERE id=?'
            )->execute([$dueOn, $dueAt, $kind, $title, $caseId, (int) $id]);
            return;
        }
    }
    $pdo->prepare(
        'INSERT INTO deadlines (document_id, case_id, due_on, due_at, kind, title, status, source_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$documentId, $caseId, $dueOn, $dueAt, $kind, $title, 'open', $sourceKey]);
}

function find_or_create_case_id(PDO $pdo, string $number): ?int
{
    if (!table_exists($pdo, 'cases')) {
        return null;
    }
    $number = trim($number);
    if ($number === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, aliases FROM cases');
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        if (strcasecmp((string) $row['case_number'], $number) === 0) {
            return (int) $row['id'];
        }
        $aliases = extra_decode($row['aliases'] ?? null);
        foreach ($aliases as $a) {
            if (is_string($a) && strcasecmp($a, $number) === 0) {
                return (int) $row['id'];
            }
        }
    }
    $pdo->prepare('INSERT INTO cases (case_number, title, status) VALUES (?, ?, ?)')
        ->execute([$number, $number, 'open']);
    return (int) $pdo->lastInsertId();
}

function seed_deadlines_from_document(PDO $pdo, int $docId, array $extra, ?string $title): void
{
    $pairs = [
        'hearing_at' => 'hearing',
        'vacate_deadline' => 'vacate',
        'end_date' => 'lease_end',
        'due_date' => 'bill_due',
        'pay_by' => 'pay_by',
    ];
    if (empty($extra['vacate_deadline']) && !empty($extra['effective_date'])) {
        $statute = (string) ($extra['statute'] ?? '');
        $looksEntry = stripos($statute, '83.53') !== false
            || stripos((string) $title, '24-hour') !== false
            || stripos((string) $title, '24hr') !== false
            || stripos((string) $title, 'notice to enter') !== false;
        $pairs['effective_date'] = $looksEntry ? 'entry' : 'vacate';
    }
    $base = $title ?: 'Document ' . $docId;
    foreach ($pairs as $src => $kind) {
        if (!isset($extra[$src]) || $extra[$src] === '' || $extra[$src] === null) {
            continue;
        }
        $raw = is_scalar($extra[$src]) ? (string) $extra[$src] : '';
        $date = parse_fact_date($raw);
        if ($date === null && preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            $date = substr($raw, 0, 10);
        }
        if ($date === null) {
            continue;
        }
        $dueAt = null;
        if (preg_match('/(\d{1,2}:\d{2})/', $raw, $hm)) {
            $dueAt = $date . ' ' . $hm[1] . ':00';
        }
        upsert_deadline($pdo, $docId, $kind, $src, $date, $dueAt, $kind . ': ' . $base);
    }
}

/** @return list<array<string,mixed>> */
function upcoming_deadlines(PDO $pdo, int $limit = 8): array
{
    if (!table_exists($pdo, 'deadlines')) {
        return [];
    }
    $stmt = $pdo->prepare(
        "SELECT dl.*, d.title AS doc_title, c.case_number
         FROM deadlines dl
         LEFT JOIN documents d ON d.id = dl.document_id
         LEFT JOIN cases c ON c.id = dl.case_id
         WHERE dl.status = 'open'
         ORDER BY (dl.due_on < CURDATE()) DESC, dl.due_on ASC, dl.id ASC
         LIMIT " . (int) $limit
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

function render_deadline_strip(PDO $pdo): void
{
    $rows = upcoming_deadlines($pdo, 8);
    if (!$rows) {
        return;
    }
    $items = deadline_items_map($pdo, array_map(static fn ($r) => (int) $r['id'], $rows));
    echo '<section class="coming-up">';
    echo '<h2>Coming up</h2>';
    echo '<ul>';
    $today = date('Y-m-d');
    foreach ($rows as $row) {
        $overdue = ($row['due_on'] ?? '') < $today;
        if (!empty($row['case_id'])) {
            $href = 'case.php?id=' . (int) $row['case_id'];
        } elseif (!empty($row['document_id'])) {
            $href = 'document.php?id=' . (int) $row['document_id'];
        } else {
            $href = 'deadline.php?id=' . (int) $row['id'];
        }
        $when = h((string) $row['due_on']);
        if (!empty($row['due_at'])) {
            $when .= ' ' . h(substr((string) $row['due_at'], 11, 5));
        }
        echo '<li class="' . ($overdue ? 'overdue' : '') . '">';
        echo '<a href="' . h($href) . '">';
        echo '<span class="when">' . $when . ($overdue ? ' · overdue' : '') . '</span>';
        echo '<span>' . h((string) $row['kind'] . ' · ' . (string) $row['title']) . '</span>';
        echo '</a>';
        echo '<form method="post" action="deadline.php" class="dl-actions">';
        echo '<input type="hidden" name="id" value="' . (int) $row['id'] . '">';
        echo '<input type="hidden" name="return" value="' . h($_SERVER['REQUEST_URI'] ?? 'index.php') . '">';
        echo '<button class="btn small" name="action" value="done" type="submit">Done</button>';
        echo '<button class="btn small ghost" name="action" value="cancelled" type="submit">Not this</button>';
        echo '</form>';
        $openItems = array_values(array_filter($items[(int) $row['id']] ?? [], static fn ($it) => ($it['status'] ?? '') === 'open'));
        if ($openItems) {
            echo '<ul class="check-list compact">';
            foreach (array_slice($openItems, 0, 5) as $item) {
                echo '<li><form method="post" action="deadline.php">';
                echo '<input type="hidden" name="item_id" value="' . (int) $item['id'] . '">';
                echo '<input type="hidden" name="return" value="' . h($_SERVER['REQUEST_URI'] ?? 'index.php') . '">';
                echo '<button class="choice" name="action" value="item_done" type="submit">○ ' . h((string) $item['title']) . '</button>';
                echo '</form></li>';
            }
            echo '</ul>';
        }
        echo '</li>';
    }
    echo '</ul></section>';
}

function handle_deadline_post(PDO $pdo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    $action = (string) ($_POST['action'] ?? '');
    $r = safe_return_url((string) ($_POST['return'] ?? 'index.php'));
    if ($action === 'item_done' || $action === 'item_open') {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        if ($itemId > 0 && table_exists($pdo, 'deadline_items')) {
            $st = $action === 'item_done' ? 'done' : 'open';
            $pdo->prepare('UPDATE deadline_items SET status = ? WHERE id = ?')->execute([$st, $itemId]);
        }
        header('Location: ' . $r, true, 303);
        exit;
    }
    if ($action === 'add_item') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = mb_substr(trim((string) ($_POST['item_title'] ?? '')), 0, 180);
        if ($id > 0 && $title !== '' && table_exists($pdo, 'deadline_items')) {
            $sort = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM deadline_items WHERE deadline_id = ?');
            $sort->execute([$id]);
            $pdo->prepare('INSERT INTO deadline_items (deadline_id, title, status, sort_order) VALUES (?, ?, ?, ?)')
                ->execute([$id, $title, 'open', (int) $sort->fetchColumn()]);
        }
        header('Location: ' . $r, true, 303);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($id < 1 || !in_array($action, ['done', 'cancelled'], true)) {
        return;
    }
    $pdo->prepare("UPDATE deadlines SET status = ? WHERE id = ? AND status = 'open'")
        ->execute([$action, $id]);
    header('Location: ' . $r, true, 303);
    exit;
}

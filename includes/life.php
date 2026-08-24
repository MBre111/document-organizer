<?php

declare(strict_types=1);

function migrate_life_schema(PDO $pdo): void
{
    if (table_exists($pdo, 'untrusted_facts') && !column_exists($pdo, 'untrusted_facts', 'journal_id')) {
        $pdo->exec('ALTER TABLE untrusted_facts ADD COLUMN journal_id INT UNSIGNED NULL AFTER document_id');
        $pdo->exec('ALTER TABLE untrusted_facts ADD INDEX idx_uf_journal (journal_id)');
    }
    if (table_exists($pdo, 'deadlines') && !column_exists($pdo, 'deadlines', 'journal_id')) {
        $pdo->exec('ALTER TABLE deadlines ADD COLUMN journal_id INT UNSIGNED NULL AFTER document_id');
        $pdo->exec('ALTER TABLE deadlines ADD INDEX idx_dl_journal (journal_id)');
    }
    if (!table_exists($pdo, 'deadline_items')) {
        $pdo->exec(
            "CREATE TABLE deadline_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                deadline_id INT UNSIGNED NOT NULL,
                title VARCHAR(180) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'open',
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_di_dl (deadline_id, status)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    if (!table_exists($pdo, 'recurring_bills')) {
        $pdo->exec(
            "CREATE TABLE recurring_bills (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                document_id INT UNSIGNED NULL,
                entity_id INT UNSIGNED NULL,
                payee VARCHAR(180) NOT NULL,
                amount DECIMAL(10,2) NULL,
                currency VARCHAR(8) NOT NULL DEFAULT 'USD',
                cadence VARCHAR(16) NOT NULL DEFAULT 'monthly',
                day_of_month TINYINT UNSIGNED NULL,
                next_due DATE NOT NULL,
                last_spawned DATE NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'manual',
                notes VARCHAR(255) NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_rb_next (status, next_due)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    if (!table_exists($pdo, 'bank_accounts')) {
        $pdo->exec(
            "CREATE TABLE bank_accounts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                fintable_id VARCHAR(64) NOT NULL,
                connection_id VARCHAR(80) NULL,
                institution VARCHAR(180) NULL,
                name VARCHAR(180) NOT NULL,
                display_name VARCHAR(180) NULL,
                type VARCHAR(80) NULL,
                currency VARCHAR(8) NOT NULL DEFAULT 'USD',
                balance DECIMAL(12,2) NULL,
                balance_available DECIMAL(12,2) NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                extra_json TEXT NULL,
                synced_at DATETIME NULL,
                UNIQUE KEY uq_ba_ft (fintable_id)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    if (!table_exists($pdo, 'bank_transactions')) {
        $pdo->exec(
            "CREATE TABLE bank_transactions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                fintable_id VARCHAR(64) NOT NULL,
                account_id INT UNSIGNED NOT NULL,
                posted_on DATE NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                currency VARCHAR(8) NOT NULL DEFAULT 'USD',
                description VARCHAR(255) NULL,
                merchant VARCHAR(180) NULL,
                category VARCHAR(80) NULL,
                extra_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_bt_ft (fintable_id),
                INDEX idx_tx_date (posted_on),
                INDEX idx_tx_acct (account_id, posted_on)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    if (!table_exists($pdo, 'app_state')) {
        $pdo->exec(
            "CREATE TABLE app_state (
                k VARCHAR(64) NOT NULL,
                v TEXT NULL,
                PRIMARY KEY (k)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    seed_pack_checklist($pdo);
    spawn_recurring_deadlines($pdo);
}

function app_state_get(PDO $pdo, string $key): ?string
{
    if (!table_exists($pdo, 'app_state')) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT v FROM app_state WHERE k = ?');
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    return $v === false ? null : (string) $v;
}

function app_state_set(PDO $pdo, string $key, ?string $value): void
{
    if (!table_exists($pdo, 'app_state')) {
        return;
    }
    $pdo->prepare('INSERT INTO app_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute([$key, $value]);
}

function next_named_weekday(string $fromYmd, string $weekday): string
{
    $map = [
        'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
        'thursday' => 4, 'friday' => 5, 'saturday' => 6,
    ];
    $want = $map[strtolower($weekday)] ?? 1;
    $t = strtotime($fromYmd . ' 12:00:00') ?: time();
    $dow = (int) date('w', $t);
    $add = ($want - $dow + 7) % 7;
    return date('Y-m-d', strtotime('+' . $add . ' days', $t));
}

function end_of_weekend(string $fromYmd): string
{
    return next_named_weekday($fromYmd, 'sunday');
}

function end_of_month_date(string $fromYmd): string
{
    $t = strtotime($fromYmd . ' 12:00:00') ?: time();
    return date('Y-m-t', $t);
}

function add_months_on_day(string $ymd, int $dayOfMonth): string
{
    $dt = new DateTimeImmutable($ymd . ' 12:00:00');
    $next = $dt->modify('first day of next month');
    $last = (int) $next->format('t');
    $d = max(1, min($dayOfMonth, $last));
    return $next->setDate((int) $next->format('Y'), (int) $next->format('n'), $d)->format('Y-m-d');
}

function parse_relative_day(string $phrase, string $fromYmd): ?string
{
    $p = strtolower(trim($phrase));
    if ($p === 'today') {
        return $fromYmd;
    }
    if ($p === 'tomorrow') {
        return date('Y-m-d', strtotime($fromYmd . ' +1 day'));
    }
    if (preg_match('/end of (?:the )?weekend|this weekend/', $p) || $p === 'sunday') {
        return end_of_weekend($fromYmd);
    }
    if (preg_match('/end of (?:the )?month|\beom\b/', $p)) {
        return end_of_month_date($fromYmd);
    }
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        if (preg_match('/\b' . $day . '\b/', $p)) {
            return next_named_weekday($fromYmd, $day);
        }
    }
    $d = parse_fact_date($phrase);
    return $d;
}

/** @return list<array{title:string,due_on:string,kind:string}> */
function journal_task_candidates(string $body, string $entryDate): array
{
    $out = [];
    $add = static function (array &$out, string $title, string $due, string $kind = 'other'): void {
        $title = mb_substr(trim($title), 0, 180);
        if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            return;
        }
        foreach ($out as $row) {
            if ($row['due_on'] === $due && strcasecmp($row['title'], $title) === 0) {
                return;
            }
        }
        $out[] = ['title' => $title, 'due_on' => $due, 'kind' => $kind];
    };

    if (preg_match('/pack|30[ -]?day|not needed/i', $body) && preg_match('/weekend|sunday/i', $body)) {
        $add($out, 'Pack anything not needed in 30 days', end_of_weekend($entryDate));
    }
    if (preg_match('/storage locker|new locker|set up.{0,40}locker/i', $body)) {
        $due = preg_match('/monday/i', $body)
            ? next_named_weekday($entryDate, 'monday')
            : $entryDate;
        $add($out, 'Set up new storage locker', $due);
    }
    if (preg_match('/\bmov(?:e|ing)\b/i', $body) && preg_match('/end of (?:the )?month|\beom\b/i', $body)) {
        $add($out, 'Move by end of month', end_of_month_date($entryDate));
    }

    if (preg_match_all('/\b(?:by|on|before|due(?: on)?)\s+([A-Za-z0-9,\/\- ]{3,40})/i', $body, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[1] as $m) {
            $phrase = trim($m[0], " \t\n\r.,;");
            $due = parse_relative_day($phrase, $entryDate);
            if ($due === null) {
                continue;
            }
            $pos = (int) $m[1];
            $start = max(0, $pos - 90);
            $snip = trim(preg_replace('/\s+/', ' ', substr($body, $start, 140)) ?? '');
            $snip = mb_substr($snip, 0, 120);
            if ($snip === '') {
                $snip = 'Follow-up by ' . $due;
            }
            $add($out, $snip, $due);
        }
    }

    return $out;
}

function propose_journal_tasks(PDO $pdo, int $journalId, string $body, string $entryDate): int
{
    if ($journalId < 1 || !table_exists($pdo, 'untrusted_facts')) {
        return 0;
    }
    $n = 0;
    foreach (journal_task_candidates($body, $entryDate) as $cand) {
        $payload = extra_encode($cand);
        $sourceKey = 'jtask:' . $journalId . ':' . substr(sha1($cand['due_on'] . '|' . strtolower($cand['title'])), 0, 10);
        $existsDl = $pdo->prepare('SELECT id FROM deadlines WHERE source_key = ? LIMIT 1');
        $existsDl->execute([$sourceKey]);
        if ($existsDl->fetch()) {
            continue;
        }
        $dupTitle = $pdo->prepare(
            'SELECT id FROM deadlines WHERE title = ? AND due_on = ? AND status = ? LIMIT 1'
        );
        $dupTitle->execute([$cand['title'], $cand['due_on'], 'open']);
        if ($dupTitle->fetch()) {
            continue;
        }
        $existsFact = $pdo->prepare(
            "SELECT id FROM untrusted_facts WHERE journal_id = ? AND fact_key = 'proposed_task' AND fact_value = ? LIMIT 1"
        );
        $existsFact->execute([$journalId, $payload]);
        if ($existsFact->fetch()) {
            continue;
        }
        $when = $cand['due_on'] === $entryDate ? 'today' : $cand['due_on'];
        $prompt = 'Add this as a task due ' . $when . '?';
        $pdo->prepare(
            'INSERT INTO untrusted_facts
             (document_id, journal_id, fact_key, fact_value, prompt, options_json, reason, importance, status)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $journalId,
            'proposed_task',
            $payload,
            mb_substr($prompt, 0, 180),
            extra_encode(['Yes — add this task', 'Skip']),
            mb_substr($cand['title'], 0, 255),
            'normal',
            'open',
        ]);
        $n++;
    }
    return $n;
}

function extract_journal_weight(PDO $pdo, int $journalId, string $body, string $entryDate): bool
{
    if (!table_exists($pdo, 'measurements')) {
        return false;
    }
    if (!preg_match('/\b(\d{2,3}(?:\.\d{1,2})?)\s*(?:pounds|pound|lbs|lb)\b/i', $body, $m)) {
        return false;
    }
    $val = (float) $m[1];
    if ($val < 80 || $val > 450) {
        return false;
    }
    $exists = $pdo->prepare("SELECT id FROM measurements WHERE kind = 'weight' AND taken_on = ? LIMIT 1");
    $exists->execute([$entryDate]);
    if ($exists->fetch()) {
        return false;
    }
    $cond = [];
    if (preg_match('/naked/i', $body)) {
        $cond[] = 'naked';
    }
    if (preg_match('/fasted|before eating|before food or drink|before eating or drinking/i', $body)) {
        $cond[] = 'fasted';
    }
    if (preg_match('/morning/i', $body)) {
        $cond[] = 'morning';
    }
    $pdo->prepare(
        'INSERT INTO measurements (taken_on, taken_at, kind, value_num, unit, conditions, journal_id, notes)
         VALUES (?, NULL, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $entryDate, 'weight', $val, 'lb',
        $cond ? implode(', ', $cond) : null,
        $journalId,
        'From journal text',
    ]);
    return true;
}

function save_journal_entry(PDO $pdo, string $date, string $body, string $title = '', string $source = 'typed'): int
{
    $pdo->prepare(
        'INSERT INTO journals (entry_date, entry_at, title, body, source) VALUES (?, NOW(), ?, ?, ?)'
    )->execute([$date, $title !== '' ? $title : null, $body, $source]);
    $jid = (int) $pdo->lastInsertId();
    extract_journal_weight($pdo, $jid, $body, $date);
    propose_journal_tasks($pdo, $jid, $body, $date);
    return $jid;
}

function confirm_proposed_task(PDO $pdo, array $fact, string $chosen): void
{
    if (stripos($chosen, 'skip') !== false || fact_is_non_answer($chosen)) {
        return;
    }
    $cand = extra_decode((string) ($fact['fact_value'] ?? ''));
    $title = trim((string) ($cand['title'] ?? ''));
    $due = (string) ($cand['due_on'] ?? '');
    $kind = (string) ($cand['kind'] ?? 'other');
    if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
        $due = parse_fact_date($chosen) ?? $due;
        if ($title === '') {
            $title = $chosen;
        }
    }
    if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
        return;
    }
    $journalId = (int) ($fact['journal_id'] ?? 0);
    $sourceKey = 'jtask:' . $journalId . ':' . substr(sha1($due . '|' . strtolower($title)), 0, 10);
    $have = $pdo->prepare('SELECT id FROM deadlines WHERE source_key = ? LIMIT 1');
    $have->execute([$sourceKey]);
    if ($have->fetch()) {
        return;
    }
    $pdo->prepare(
        'INSERT INTO deadlines (document_id, journal_id, due_on, kind, title, status, source_key)
         VALUES (NULL, ?, ?, ?, ?, ?, ?)'
    )->execute([$journalId > 0 ? $journalId : null, $due, $kind, mb_substr($title, 0, 180), 'open', $sourceKey]);
}

function seed_pack_checklist(PDO $pdo): void
{
    if (!table_exists($pdo, 'deadline_items') || !table_exists($pdo, 'deadlines')) {
        return;
    }
    $find = $pdo->query("SELECT id FROM deadlines WHERE source_key = 'journal:pack-30-day' LIMIT 1");
    $id = $find ? $find->fetchColumn() : false;
    if (!$id) {
        return;
    }
    $id = (int) $id;
    $n = $pdo->prepare('SELECT COUNT(*) FROM deadline_items WHERE deadline_id = ?');
    $n->execute([$id]);
    if ((int) $n->fetchColumn() > 0) {
        return;
    }
    $ins = $pdo->prepare(
        'INSERT INTO deadline_items (deadline_id, title, status, sort_order) VALUES (?, ?, ?, ?)'
    );
    $ins->execute([$id, 'Sort the 30-day-not-needed pile', 'open', 1]);
    $ins->execute([$id, 'Box it for the locker', 'open', 2]);
    $ins->execute([$id, 'Ready to drop at the locker Monday', 'open', 3]);
}

/** @return list<array<string,mixed>> */
function deadline_items(PDO $pdo, int $deadlineId): array
{
    if ($deadlineId < 1 || !table_exists($pdo, 'deadline_items')) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM deadline_items WHERE deadline_id = ? ORDER BY sort_order, id');
    $stmt->execute([$deadlineId]);
    return $stmt->fetchAll();
}

/** @return array<int, list<array<string,mixed>>> */
function deadline_items_map(PDO $pdo, array $deadlineIds): array
{
    $out = [];
    foreach ($deadlineIds as $id) {
        $out[(int) $id] = [];
    }
    if (!$deadlineIds || !table_exists($pdo, 'deadline_items')) {
        return $out;
    }
    $in = implode(',', array_map('intval', $deadlineIds));
    $rows = $pdo->query(
        "SELECT * FROM deadline_items WHERE deadline_id IN ($in) ORDER BY sort_order, id"
    )->fetchAll();
    foreach ($rows as $row) {
        $out[(int) $row['deadline_id']][] = $row;
    }
    return $out;
}

function safe_return_url(?string $r, string $fallback = 'index.php'): string
{
    $r = (string) $r;
    if ($r === '' || str_starts_with($r, '/') || str_contains($r, '://')) {
        return $fallback;
    }
    if (preg_match('/^(index|document|case|entity|facts|journal|deadline|money)\.php([\?#].*)?$/', $r)) {
        return $r;
    }
    return $fallback;
}

function upsert_measurement_weight(PDO $pdo, string $date, float $value, string $conditions, ?int $journalId = null): void
{
    $find = $pdo->prepare("SELECT id FROM measurements WHERE kind = 'weight' AND taken_on = ? LIMIT 1");
    $find->execute([$date]);
    $id = $find->fetchColumn();
    if ($id) {
        $pdo->prepare(
            'UPDATE measurements SET value_num = ?, conditions = ?, journal_id = COALESCE(journal_id, ?) WHERE id = ?'
        )->execute([$value, $conditions !== '' ? $conditions : null, $journalId, (int) $id]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO measurements (taken_on, taken_at, kind, value_num, unit, conditions, journal_id, notes)
         VALUES (?, NOW(), ?, ?, ?, ?, ?, ?)'
    )->execute([$date, 'weight', $value, 'lb', $conditions !== '' ? $conditions : null, $journalId, 'Today log']);
}

function handle_today_post(PDO $pdo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    if ((string) ($_POST['action'] ?? '') !== 'today_log') {
        return;
    }
    $date = trim((string) ($_POST['entry_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }
    $body = trim((string) ($_POST['body'] ?? ''));
    $weightRaw = trim((string) ($_POST['weight'] ?? ''));
    $conds = [];
    if (!empty($_POST['naked'])) {
        $conds[] = 'naked';
    }
    if (!empty($_POST['fasted'])) {
        $conds[] = 'fasted';
    }
    $conds[] = 'morning';
    $journalId = null;
    if ($body !== '') {
        $journalId = save_journal_entry($pdo, $date, $body, '', 'typed');
    }
    if ($weightRaw !== '' && is_numeric($weightRaw)) {
        $w = (float) $weightRaw;
        if ($w >= 80 && $w <= 450) {
            upsert_measurement_weight($pdo, $date, $w, implode(', ', $conds), $journalId);
        }
    }
    header('Location: index.php', true, 303);
    exit;
}

function render_morning_log(PDO $pdo): void
{
    $today = date('Y-m-d');
    $w = null;
    if (table_exists($pdo, 'measurements')) {
        $stmt = $pdo->prepare("SELECT * FROM measurements WHERE kind = 'weight' AND taken_on = ? LIMIT 1");
        $stmt->execute([$today]);
        $w = $stmt->fetch() ?: null;
    }
    $recent = [];
    if (table_exists($pdo, 'measurements')) {
        $recent = $pdo->query(
            "SELECT taken_on, value_num, conditions FROM measurements
             WHERE kind = 'weight' ORDER BY taken_on DESC, id DESC LIMIT 5"
        )->fetchAll();
    }
    echo '<section class="morning-log">';
    echo '<h2>Morning log</h2>';
    echo '<form method="post" class="edit-doc">';
    echo '<input type="hidden" name="action" value="today_log">';
    echo '<input type="hidden" name="entry_date" value="' . h($today) . '">';
    echo '<div class="log-row">';
    echo '<label>Weight (lb) <input type="number" name="weight" step="0.1" min="80" max="450" inputmode="decimal" value="'
        . h($w ? (string) $w['value_num'] : '') . '" placeholder="196.9"></label>';
    echo '<label class="switch"><input type="checkbox" name="naked" value="1" checked> Naked</label>';
    echo '<label class="switch"><input type="checkbox" name="fasted" value="1" checked> Fasted</label>';
    echo '</div>';
    echo '<label>Journal <textarea name="body" rows="4" placeholder="What happened today. Plans and dates become tasks to tap."></textarea></label>';
    echo '<button class="btn" type="submit">Save</button>';
    echo '</form>';
    if ($w) {
        echo '<p class="muted">Today: ' . h((string) $w['value_num']) . ' lb'
            . ($w['conditions'] ? ' · ' . h((string) $w['conditions']) : '') . '</p>';
    }
    if ($recent) {
        echo '<p class="muted weight-strip">';
        $bits = [];
        foreach ($recent as $row) {
            $bits[] = h(substr((string) $row['taken_on'], 5)) . ' ' . h((string) $row['value_num']);
        }
        echo implode(' · ', $bits);
        echo '</p>';
    }
    echo '</section>';
}

function spawn_recurring_deadlines(PDO $pdo): void
{
    if (!table_exists($pdo, 'recurring_bills') || !table_exists($pdo, 'deadlines')) {
        return;
    }
    $horizon = date('Y-m-d', strtotime('+14 days'));
    $rows = $pdo->query("SELECT * FROM recurring_bills WHERE status = 'active'")->fetchAll();
    foreach ($rows as $row) {
        $next = (string) $row['next_due'];
        $dom = (int) ($row['day_of_month'] ?: (int) substr($next, 8, 2));
        $guard = 0;
        while ($next <= $horizon && $guard < 24) {
            $sourceKey = 'recur:' . (int) $row['id'] . ':' . $next;
            $have = $pdo->prepare('SELECT id FROM deadlines WHERE source_key = ? LIMIT 1');
            $have->execute([$sourceKey]);
            if (!$have->fetch()) {
                $amt = $row['amount'] !== null ? (' $' . number_format((float) $row['amount'], 2)) : '';
                $title = 'Pay ' . $row['payee'] . $amt;
                $pdo->prepare(
                    'INSERT INTO deadlines (document_id, due_on, kind, title, status, source_key)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $row['document_id'] ? (int) $row['document_id'] : null,
                    $next,
                    'bill_due',
                    mb_substr($title, 0, 180),
                    'open',
                    $sourceKey,
                ]);
            }
            $pdo->prepare('UPDATE recurring_bills SET last_spawned = ?, next_due = ? WHERE id = ?')
                ->execute([$next, add_months_on_day($next, $dom), (int) $row['id']]);
            $fresh = $pdo->prepare('SELECT next_due FROM recurring_bills WHERE id = ?');
            $fresh->execute([(int) $row['id']]);
            $next = (string) $fresh->fetchColumn();
            $guard++;
        }
    }
}

function save_recurring_bill(PDO $pdo, array $data): int
{
    $payee = mb_substr(trim((string) ($data['payee'] ?? '')), 0, 180);
    $next = (string) ($data['next_due'] ?? '');
    if ($payee === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $next)) {
        return 0;
    }
    $amount = $data['amount'] ?? null;
    if ($amount === '' || $amount === null) {
        $amount = null;
    } else {
        $amount = (float) $amount;
    }
    $dom = (int) ($data['day_of_month'] ?? substr($next, 8, 2));
    if ($dom < 1 || $dom > 31) {
        $dom = (int) substr($next, 8, 2);
    }
    $docId = (int) ($data['document_id'] ?? 0);
    if ($docId > 0) {
        $find = $pdo->prepare("SELECT id FROM recurring_bills WHERE document_id = ? AND status = 'active' LIMIT 1");
        $find->execute([$docId]);
        $id = $find->fetchColumn();
        if ($id) {
            $pdo->prepare(
                'UPDATE recurring_bills SET payee=?, amount=?, day_of_month=?, next_due=?, notes=? WHERE id=?'
            )->execute([
                $payee, $amount, $dom, $next,
                mb_substr(trim((string) ($data['notes'] ?? '')), 0, 255) ?: null,
                (int) $id,
            ]);
            spawn_recurring_deadlines($pdo);
            return (int) $id;
        }
    }
    $pdo->prepare(
        'INSERT INTO recurring_bills (document_id, payee, amount, currency, cadence, day_of_month, next_due, source, notes, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $docId > 0 ? $docId : null,
        $payee,
        $amount,
        'USD',
        'monthly',
        $dom,
        $next,
        (string) ($data['source'] ?? 'manual'),
        mb_substr(trim((string) ($data['notes'] ?? '')), 0, 255) ?: null,
        'active',
    ]);
    $id = (int) $pdo->lastInsertId();
    spawn_recurring_deadlines($pdo);
    return $id;
}

/** @return array{payee:string,amount:?string,day:?int,next:?string} */
function recurring_prefill(array $doc, array $extra): array
{
    $payee = (string) ($doc['source_org'] ?? '');
    $amount = null;
    foreach (['amount_due', 'rent', 'amount'] as $k) {
        if (isset($extra[$k]) && $extra[$k] !== '' && $extra[$k] !== null) {
            $amount = is_numeric($extra[$k]) ? (string) $extra[$k] : (string) (parse_fact_number((string) $extra[$k]) ?? '');
            if ($amount === '') {
                $amount = null;
            }
            break;
        }
    }
    $next = null;
    foreach (['due_date', 'pay_by'] as $k) {
        if (!empty($extra[$k])) {
            $d = parse_fact_date((string) $extra[$k]);
            if ($d) {
                $next = $d;
                break;
            }
        }
    }
    $day = $next ? (int) substr($next, 8, 2) : null;
    if ($next && $next < date('Y-m-d') && $day) {
        $cursor = $next;
        $guard = 0;
        while ($cursor < date('Y-m-d') && $guard < 36) {
            $cursor = add_months_on_day($cursor, $day);
            $guard++;
        }
        $next = $cursor;
    }
    return ['payee' => $payee, 'amount' => $amount, 'day' => $day, 'next' => $next];
}

function render_recurring_form(PDO $pdo, array $doc, array $extra): void
{
    if (!table_exists($pdo, 'recurring_bills')) {
        return;
    }
    $type = (string) ($doc['doc_type'] ?? '');
    $ok = in_array($type, ['utility_bill', 'housing_lease', 'bank_letter', 'medical_bill', 'tax_notice', 'receipt', 'other'], true);
    if (!$ok) {
        return;
    }
    $existing = $pdo->prepare("SELECT * FROM recurring_bills WHERE document_id = ? AND status = 'active' LIMIT 1");
    $existing->execute([(int) $doc['id']]);
    $row = $existing->fetch() ?: null;
    $pre = recurring_prefill($doc, $extra);
    echo '<h2>Monthly bill</h2>';
    if ($row) {
        echo '<p class="muted">Repeats monthly. Next due ' . h((string) $row['next_due']);
        if ($row['amount'] !== null) {
            echo ' · $' . h(number_format((float) $row['amount'], 2));
        }
        echo '. <a href="money.php">Money</a></p>';
        return;
    }
    echo '<p class="muted">Mark this so Today grows the next due date. Amounts come from the document — change them if the extract was wrong.</p>';
    echo '<form method="post" class="edit-doc">';
    echo '<input type="hidden" name="action" value="recurring">';
    echo '<label>Payee <input name="payee" value="' . h($pre['payee']) . '" required></label>';
    echo '<label>Amount <input name="amount" type="number" step="0.01" value="' . h((string) ($pre['amount'] ?? '')) . '"></label>';
    echo '<label>Day of month <input name="day_of_month" type="number" min="1" max="31" value="'
        . h((string) ($pre['day'] ?? '')) . '"></label>';
    echo '<label>Next due <input type="date" name="next_due" value="' . h((string) ($pre['next'] ?? '')) . '" required></label>';
    echo '<button class="btn" type="submit">Start monthly</button>';
    echo '</form>';
}

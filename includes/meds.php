<?php

declare(strict_types=1);

function migrate_meds_schema(PDO $pdo): void
{
    if (!table_exists($pdo, 'medications')) {
        $pdo->exec(
            "CREATE TABLE medications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                kind VARCHAR(16) NOT NULL DEFAULT 'supplement',
                dose VARCHAR(80) NULL,
                timing VARCHAR(16) NOT NULL DEFAULT 'morning',
                time_window VARCHAR(32) NULL,
                notes VARCHAR(255) NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_med_status (status, timing)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    if (!table_exists($pdo, 'med_logs')) {
        $pdo->exec(
            "CREATE TABLE med_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                medication_id INT UNSIGNED NOT NULL,
                taken_on DATE NOT NULL,
                taken_at DATETIME NULL,
                slot VARCHAR(16) NOT NULL DEFAULT 'morning',
                status VARCHAR(16) NOT NULL DEFAULT 'taken',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_med_day_slot (medication_id, taken_on, slot),
                INDEX idx_ml_day (taken_on)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
}

function med_timings(): array
{
    return [
        'morning' => 'Morning',
        'evening' => 'Evening',
        'both' => 'Morning and evening',
        'as_needed' => 'As needed',
    ];
}

function med_kinds(): array
{
    return [
        'medication' => 'Medication',
        'supplement' => 'Supplement',
    ];
}

function med_slots_for_timing(string $timing): array
{
    return match ($timing) {
        'both' => ['morning', 'evening'],
        'as_needed' => ['as_needed'],
        'evening' => ['evening'],
        default => ['morning'],
    };
}

function med_current_slots(): array
{
    $h = (int) date('G');
    if ($h < 15) {
        return ['morning', 'as_needed'];
    }
    return ['morning', 'evening', 'as_needed'];
}

function medications_active(PDO $pdo): array
{
    if (!table_exists($pdo, 'medications')) {
        return [];
    }
    return $pdo->query(
        "SELECT * FROM medications WHERE status = 'active' ORDER BY kind, sort_order, name"
    )->fetchAll();
}

function med_log_map(PDO $pdo, string $date): array
{
    if (!table_exists($pdo, 'med_logs')) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT medication_id, slot, status FROM med_logs WHERE taken_on = ?');
    $stmt->execute([$date]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['medication_id'] . ':' . $row['slot']] = (string) $row['status'];
    }
    return $out;
}

function med_set_log(PDO $pdo, int $medId, string $date, string $slot, string $status): void
{
    if ($medId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return;
    }
    if (!in_array($slot, ['morning', 'evening', 'as_needed'], true)) {
        $slot = 'morning';
    }
    if ($status === 'clear') {
        $pdo->prepare('DELETE FROM med_logs WHERE medication_id = ? AND taken_on = ? AND slot = ?')
            ->execute([$medId, $date, $slot]);
        sync_meds_habit($pdo, $date);
        return;
    }
    if (!in_array($status, ['taken', 'skipped'], true)) {
        $status = 'taken';
    }
    $pdo->prepare(
        'INSERT INTO med_logs (medication_id, taken_on, taken_at, slot, status)
         VALUES (?, ?, NOW(), ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), taken_at = VALUES(taken_at)'
    )->execute([$medId, $date, $slot, $status]);
    sync_meds_habit($pdo, $date);
}

function meds_morning_complete(PDO $pdo, string $date): bool
{
    $rows = medications_active($pdo);
    if (!$rows) {
        return false;
    }
    $logs = med_log_map($pdo, $date);
    foreach ($rows as $m) {
        $timing = (string) $m['timing'];
        if ($timing === 'as_needed' || $timing === 'evening') {
            continue;
        }
        $key = (int) $m['id'] . ':morning';
        if (($logs[$key] ?? '') !== 'taken') {
            return false;
        }
    }
    return true;
}

function sync_meds_habit(PDO $pdo, string $date): void
{
    if (!table_exists($pdo, 'habits') || !medications_active($pdo)) {
        return;
    }
    $hid = $pdo->query("SELECT id FROM habits WHERE slug = 'meds' LIMIT 1")->fetchColumn();
    if (!$hid) {
        return;
    }
    habit_set($pdo, (int) $hid, $date, meds_morning_complete($pdo, $date));
}

function save_medication(PDO $pdo, array $data): int
{
    $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 120);
    if ($name === '') {
        return 0;
    }
    $kind = (string) ($data['kind'] ?? 'supplement');
    if (!isset(med_kinds()[$kind])) {
        $kind = 'supplement';
    }
    $timing = (string) ($data['timing'] ?? 'morning');
    if (!isset(med_timings()[$timing])) {
        $timing = 'morning';
    }
    $dose = mb_substr(trim((string) ($data['dose'] ?? '')), 0, 80);
    $window = mb_substr(trim((string) ($data['time_window'] ?? '')), 0, 32);
    $notes = mb_substr(trim((string) ($data['notes'] ?? '')), 0, 255);
    $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM medications')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO medications (name, kind, dose, timing, time_window, notes, sort_order, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $name,
        $kind,
        $dose !== '' ? $dose : null,
        $timing,
        $window !== '' ? $window : null,
        $notes !== '' ? $notes : null,
        $sort,
        'active',
    ]);
    return (int) $pdo->lastInsertId();
}

function set_medication_status(PDO $pdo, int $id, string $status): void
{
    if ($id < 1 || !in_array($status, ['active', 'paused', 'stopped'], true)) {
        return;
    }
    $pdo->prepare('UPDATE medications SET status = ? WHERE id = ?')->execute([$status, $id]);
}

function render_meds_today(PDO $pdo): void
{
    $date = date('Y-m-d');
    $rows = medications_active($pdo);
    echo '<section class="coming-up meds-today">';
    echo '<h2>Meds</h2>';
    if (!$rows) {
        echo '<p class="muted">No names on file — the old sheet only had time windows. Add what you actually take.</p>';
        echo '<p><a class="btn" href="meds.php">Add meds &amp; supplements</a></p>';
        echo '</section>';
        return;
    }
    $logs = med_log_map($pdo, $date);
    $show = med_current_slots();
    echo '<ul class="check-list">';
    $shown = 0;
    foreach ($rows as $m) {
        foreach (med_slots_for_timing((string) $m['timing']) as $slot) {
            if (!in_array($slot, $show, true) && $slot !== 'morning') {
                continue;
            }
            if ($slot === 'evening' && !in_array('evening', $show, true)) {
                continue;
            }
            $key = (int) $m['id'] . ':' . $slot;
            $st = $logs[$key] ?? '';
            $on = $st === 'taken';
            $skip = $st === 'skipped';
            $label = (string) $m['name'];
            if (!empty($m['dose'])) {
                $label .= ' · ' . $m['dose'];
            }
            if ($slot === 'evening') {
                $label .= ' (evening)';
            } elseif ($slot === 'as_needed') {
                $label .= ' (as needed)';
            }
            echo '<li class="' . ($on ? 'done' : '') . ($skip ? ' skipped' : '') . '">';
            echo '<form method="post">';
            echo '<input type="hidden" name="action" value="med_log">';
            echo '<input type="hidden" name="med_id" value="' . (int) $m['id'] . '">';
            echo '<input type="hidden" name="slot" value="' . h($slot) . '">';
            echo '<input type="hidden" name="status" value="' . ($on ? 'clear' : 'taken') . '">';
            echo '<button class="choice" type="submit">' . ($on ? '✓ ' : ($skip ? '– ' : '○ ')) . h($label) . '</button>';
            echo '</form></li>';
            $shown++;
        }
    }
    echo '</ul>';
    if ($shown === 0) {
        echo '<p class="muted">Nothing due this part of the day.</p>';
    }
    echo '<p class="muted"><a href="meds.php">Edit list</a></p>';
    echo '</section>';
}

<?php

declare(strict_types=1);

function migrate_habits_schema(PDO $pdo): void
{
    if (!table_exists($pdo, 'habits')) {
        $pdo->exec(
            "CREATE TABLE habits (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(32) NOT NULL,
                name VARCHAR(80) NOT NULL,
                keywords TEXT NULL,
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                UNIQUE KEY uq_habit_slug (slug)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    if (!table_exists($pdo, 'habit_logs')) {
        $pdo->exec(
            "CREATE TABLE habit_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                habit_id INT UNSIGNED NOT NULL,
                done_on DATE NOT NULL,
                journal_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_habit_day (habit_id, done_on)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    seed_habits($pdo);
}

function seed_habits(PDO $pdo): void
{
    $rows = [
        ['sauna', 'Sauna', 'sauna', 10],
        ['meds', 'Meds', 'medication,medications,supplements,took my morning', 20],
        ['dog-walk', 'Dog walk', 'dog walk,dogs for a walk,walked the dog,took my dogs', 30],
    ];
    $ins = $pdo->prepare(
        'INSERT INTO habits (slug, name, keywords, sort_order, status)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), keywords = VALUES(keywords), sort_order = VALUES(sort_order)'
    );
    foreach ($rows as $r) {
        $ins->execute([$r[0], $r[1], $r[2], $r[3], 'active']);
    }
}

/** @return list<array<string,mixed>> */
function habits_today(PDO $pdo, string $date): array
{
    if (!table_exists($pdo, 'habits')) {
        return [];
    }
    $stmt = $pdo->prepare(
        "SELECT h.*, l.id AS log_id
         FROM habits h
         LEFT JOIN habit_logs l ON l.habit_id = h.id AND l.done_on = ?
         WHERE h.status = 'active'
         ORDER BY h.sort_order, h.id"
    );
    $stmt->execute([$date]);
    return $stmt->fetchAll();
}

function habit_set(PDO $pdo, int $habitId, string $date, bool $done, ?int $journalId = null): void
{
    if ($habitId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return;
    }
    if ($done) {
        $pdo->prepare(
            'INSERT INTO habit_logs (habit_id, done_on, journal_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE journal_id = COALESCE(journal_id, VALUES(journal_id))'
        )->execute([$habitId, $date, $journalId]);
        return;
    }
    $pdo->prepare('DELETE FROM habit_logs WHERE habit_id = ? AND done_on = ?')->execute([$habitId, $date]);
}

function tick_habits_from_text(PDO $pdo, string $body, string $date, ?int $journalId = null): void
{
    if (!table_exists($pdo, 'habits')) {
        return;
    }
    $lower = mb_strtolower($body);
    $habits = $pdo->query("SELECT * FROM habits WHERE status = 'active'")->fetchAll();
    foreach ($habits as $h) {
        $hit = false;
        foreach (explode(',', (string) ($h['keywords'] ?? '')) as $kw) {
            $kw = trim(mb_strtolower($kw));
            if ($kw !== '' && str_contains($lower, $kw)) {
                $hit = true;
                break;
            }
        }
        if ($hit) {
            habit_set($pdo, (int) $h['id'], $date, true, $journalId);
        }
    }
}

function add_habit(PDO $pdo, string $name): int
{
    $name = mb_substr(trim($name), 0, 80);
    if ($name === '') {
        return 0;
    }
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
    $slug = mb_substr($slug !== '' ? $slug : 'habit', 0, 32);
    $max = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM habits')->fetchColumn();
    try {
        $pdo->prepare('INSERT INTO habits (slug, name, keywords, sort_order, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$slug, $name, strtolower($name), $max + 10, 'active']);
        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        return 0;
    }
}

function render_habits(PDO $pdo): void
{
    $date = date('Y-m-d');
    $rows = habits_today($pdo, $date);
    if (!$rows) {
        return;
    }
    $hideMeds = function_exists('medications_active') && medications_active($pdo);
    echo '<section class="coming-up habits">';
    echo '<h2>Habits</h2>';
    echo '<ul class="check-list">';
    foreach ($rows as $h) {
        if ($hideMeds && ($h['slug'] ?? '') === 'meds') {
            continue;
        }
        $on = !empty($h['log_id']);
        echo '<li class="' . ($on ? 'done' : '') . '">';
        echo '<form method="post">';
        echo '<input type="hidden" name="action" value="habit_toggle">';
        echo '<input type="hidden" name="habit_id" value="' . (int) $h['id'] . '">';
        echo '<input type="hidden" name="done" value="' . ($on ? '0' : '1') . '">';
        echo '<button class="choice" type="submit">' . ($on ? '✓ ' : '○ ') . h((string) $h['name']) . '</button>';
        echo '</form></li>';
    }
    echo '</ul>';
    echo '<form method="post" class="habit-add">';
    echo '<input type="hidden" name="action" value="habit_add">';
    echo '<input name="habit_name" placeholder="Add a habit" maxlength="80">';
    echo '<button class="btn small ghost" type="submit">Add</button>';
    echo '</form></section>';
}

function link_journal_entities(PDO $pdo, int $journalId, string $body): void
{
    if ($journalId < 1 || !table_exists($pdo, 'journal_entities')) {
        return;
    }
    $ents = $pdo->query('SELECT id, kind, name FROM entities')->fetchAll();
    $ins = $pdo->prepare('INSERT IGNORE INTO journal_entities (journal_id, entity_id, role) VALUES (?, ?, ?)');
    $lower = mb_strtolower($body);
    foreach ($ents as $e) {
        $name = trim((string) $e['name']);
        if (mb_strlen($name) < 3) {
            continue;
        }
        $hit = mb_stripos($body, $name) !== false;
        if (!$hit && ($e['kind'] ?? '') === 'person') {
            $first = trim(explode(' ', $name)[0]);
            if (mb_strlen($first) >= 4) {
                $same = 0;
                foreach ($ents as $o) {
                    if (($o['kind'] ?? '') === 'person' && strncasecmp((string) $o['name'], $first, mb_strlen($first)) === 0) {
                        $same++;
                    }
                }
                if ($same === 1 && preg_match('/\b' . preg_quote($first, '/') . '\b/iu', $body)) {
                    $hit = true;
                }
            }
        }
        if ($hit) {
            $ins->execute([$journalId, (int) $e['id'], 'mentions']);
        }
    }
}

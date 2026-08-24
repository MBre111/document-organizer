<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';

function db(): PDO
{
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']);
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    ensure_schema($pdo);
    return $pdo;
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function storage_path(string $relative = ''): string
{
    global $config;
    $base = rtrim($config['storage'], '/\\');
    if ($relative === '') {
        return $base;
    }
    return $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

function public_file_url(string $storedPath): string
{
    return 'file.php?p=' . rawurlencode($storedPath);
}

function ensure_schema(PDO $pdo): void
{
    $cols = $pdo->query("SHOW COLUMNS FROM files LIKE 'page_no'")->fetch();
    if (!$cols) {
        $pdo->exec('ALTER TABLE files ADD COLUMN page_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER sha256');
    }
    $tbl = $pdo->query("SHOW TABLES LIKE 'untrusted_facts'")->fetch();
    if (!$tbl) {
        $pdo->exec(
            'CREATE TABLE untrusted_facts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                document_id INT UNSIGNED NULL,
                entity_id INT UNSIGNED NULL,
                fact_key VARCHAR(64) NOT NULL,
                fact_value TEXT NOT NULL,
                prompt VARCHAR(180) NULL,
                options_json TEXT NULL,
                reason VARCHAR(255) NULL,
                importance VARCHAR(16) NOT NULL DEFAULT \'normal\',
                status VARCHAR(16) NOT NULL DEFAULT \'open\',
                resolved_note TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                resolved_at DATETIME NULL,
                INDEX idx_uf_status (status),
                INDEX idx_uf_importance (importance),
                INDEX idx_uf_doc (document_id)
            ) DEFAULT CHARSET=utf8mb4'
        );
    } else {
        $promptCol = $pdo->query("SHOW COLUMNS FROM untrusted_facts LIKE 'prompt'")->fetch();
        if (!$promptCol) {
            $pdo->exec('ALTER TABLE untrusted_facts ADD COLUMN prompt VARCHAR(180) NULL AFTER fact_value');
        }
        $optCol = $pdo->query("SHOW COLUMNS FROM untrusted_facts LIKE 'options_json'")->fetch();
        if (!$optCol) {
            $pdo->exec('ALTER TABLE untrusted_facts ADD COLUMN options_json TEXT NULL AFTER prompt');
        }
    }
    seed_untrusted_options($pdo);
    migrate_catalog_schema($pdo);
}

function column_exists(PDO $pdo, string $table, string $col): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$col]);
    return (bool) $stmt->fetch();
}

function migrate_catalog_schema(PDO $pdo): void
{
    if (!column_exists($pdo, 'documents', 'case_id')) {
        $pdo->exec('ALTER TABLE documents ADD COLUMN case_id INT UNSIGNED NULL AFTER review_status');
        $pdo->exec('ALTER TABLE documents ADD INDEX idx_case (case_id)');
    }
    if (!column_exists($pdo, 'files', 'ocr_text')) {
        $pdo->exec("ALTER TABLE files ADD COLUMN ocr_text MEDIUMTEXT NULL");
        $pdo->exec("ALTER TABLE files ADD COLUMN ocr_status VARCHAR(16) NULL DEFAULT NULL");
        $pdo->exec('ALTER TABLE files ADD COLUMN ocr_at DATETIME NULL');
    }
    $cases = $pdo->query("SHOW TABLES LIKE 'cases'")->fetch();
    if (!$cases) {
        $pdo->exec(
            "CREATE TABLE cases (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                case_number VARCHAR(64) NOT NULL,
                title VARCHAR(180) NULL,
                court VARCHAR(180) NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'open',
                aliases TEXT NULL,
                notes TEXT NULL,
                extra_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_case_number (case_number)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    $dl = $pdo->query("SHOW TABLES LIKE 'deadlines'")->fetch();
    if (!$dl) {
        $pdo->exec(
            "CREATE TABLE deadlines (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                document_id INT UNSIGNED NULL,
                case_id INT UNSIGNED NULL,
                entity_id INT UNSIGNED NULL,
                due_on DATE NOT NULL,
                due_at DATETIME NULL,
                kind VARCHAR(32) NOT NULL DEFAULT 'other',
                title VARCHAR(180) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'open',
                source_key VARCHAR(64) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_dl_due (status, due_on),
                INDEX idx_dl_doc (document_id, source_key)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    $links = $pdo->query("SHOW TABLES LIKE 'document_links'")->fetch();
    if (!$links) {
        $pdo->exec(
            "CREATE TABLE document_links (
                from_id INT UNSIGNED NOT NULL,
                to_id INT UNSIGNED NOT NULL,
                kind VARCHAR(32) NOT NULL DEFAULT 'related',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (from_id, to_id, kind),
                INDEX idx_link_to (to_id)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    $journals = $pdo->query("SHOW TABLES LIKE 'journals'")->fetch();
    if (!$journals) {
        $pdo->exec(
            "CREATE TABLE journals (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                entry_date DATE NOT NULL,
                entry_at DATETIME NULL,
                title VARCHAR(180) NULL,
                body TEXT NOT NULL,
                source VARCHAR(32) NOT NULL DEFAULT 'dictation',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_journal_date (entry_date)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    $je = $pdo->query("SHOW TABLES LIKE 'journal_entities'")->fetch();
    if (!$je) {
        $pdo->exec(
            "CREATE TABLE journal_entities (
                journal_id INT UNSIGNED NOT NULL,
                entity_id INT UNSIGNED NOT NULL,
                role VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (journal_id, entity_id, role),
                INDEX idx_je_ent (entity_id)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    $ms = $pdo->query("SHOW TABLES LIKE 'measurements'")->fetch();
    if (!$ms) {
        $pdo->exec(
            "CREATE TABLE measurements (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                taken_on DATE NOT NULL,
                taken_at DATETIME NULL,
                kind VARCHAR(32) NOT NULL DEFAULT 'weight',
                value_num DECIMAL(8,2) NOT NULL,
                unit VARCHAR(16) NOT NULL DEFAULT 'lb',
                conditions VARCHAR(180) NULL,
                journal_id INT UNSIGNED NULL,
                notes VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ms_kind_date (kind, taken_on)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    seed_known_cases($pdo);
    merge_windsor_llc($pdo);
    seed_all_deadlines($pdo);
    prune_deadline_noise($pdo);
    retag_entry_notices($pdo);
    seed_journal_2026_08_22($pdo);
    migrate_life_schema($pdo);
}

function retag_entry_notices(PDO $pdo): void
{
    if (!table_exists($pdo, 'deadlines')) {
        return;
    }
    $pdo->exec(
        "UPDATE deadlines dl
         JOIN documents d ON d.id = dl.document_id
         SET dl.kind = 'entry',
             dl.title = REPLACE(dl.title, 'vacate: ', 'entry: ')
         WHERE dl.source_key = 'effective_date'
           AND (dl.kind = 'vacate' OR d.title LIKE '%24-hour%' OR d.title LIKE '%notice to enter%'
                OR CAST(d.extra_json AS CHAR) LIKE '%83.53%')"
    );
}

function prune_deadline_noise(PDO $pdo): void
{
    if (!table_exists($pdo, 'deadlines')) {
        return;
    }
    $pdo->exec("DELETE FROM deadlines WHERE kind = 'lease_start' AND due_on < CURDATE()");
    $pdo->exec(
        "DELETE dl FROM deadlines dl
         JOIN documents d ON d.id = dl.document_id
         WHERE d.review_status = 'out_of_scope'"
    );
    $pdo->exec(
        "DELETE e FROM deadlines e
         INNER JOIN deadlines v ON v.document_id = e.document_id AND v.source_key = 'vacate_deadline'
         WHERE e.source_key = 'effective_date'"
    );
}

function seed_known_cases(PDO $pdo): void
{
    $have = $pdo->query('SELECT COUNT(*) FROM cases')->fetchColumn();
    if ((int) $have > 0) {
        return;
    }
    $pdo->prepare(
        'INSERT INTO cases (case_number, title, court, status, aliases) VALUES (?,?,?,?,?)'
    )->execute([
        '2026-CC-6082-WS',
        '7312 Windsor, LLC v. Michael David Bredin (eviction)',
        'Pasco County Court, 6th Circuit, Division V',
        'open',
        json_encode(['2026CC100608WS', '2026-CC-6082-WS']),
    ]);
    $cc = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO cases (case_number, title, court, status, aliases) VALUES (?,?,?,?,?)'
    )->execute([
        '2026SC1800WS',
        '7312 Windsor, LLC v. Michael David Bredin (Mar 2026 pretrial / quash)',
        'Pasco County Court, 6th Circuit, Division S, Courtroom 2B',
        'open',
        json_encode(['2026-SC-1800-WS', '2026SC1800WS']),
    ]);
    $sc = (int) $pdo->lastInsertId();
    foreach ([2, 7, 8] as $docId) {
        $pdo->prepare('UPDATE documents SET case_id = ? WHERE id = ?')->execute([$cc, $docId]);
    }
    foreach ([16, 17, 18] as $docId) {
        $pdo->prepare('UPDATE documents SET case_id = ? WHERE id = ?')->execute([$sc, $docId]);
    }
    $pdo->prepare("UPDATE entities SET name = '2026-CC-6082-WS', notes = 'Canonical eviction case' WHERE kind = 'case' AND name = 'Pasco eviction 2026 (7312 Windsor)'")
        ->execute();
}

function merge_windsor_llc(PDO $pdo): void
{
    $keep = $pdo->query("SELECT id FROM entities WHERE kind='org' AND name='7312 Windsor, LLC' LIMIT 1")->fetchColumn();
    $drop = $pdo->query("SELECT id FROM entities WHERE kind='org' AND name='7312 Windsor LLC' LIMIT 1")->fetchColumn();
    if (!$keep || !$drop || (int) $keep === (int) $drop) {
        return;
    }
    $keep = (int) $keep;
    $drop = (int) $drop;
    $links = $pdo->prepare('SELECT document_id, role FROM document_entities WHERE entity_id = ?');
    $links->execute([$drop]);
    $ins = $pdo->prepare('INSERT IGNORE INTO document_entities (document_id, entity_id, role) VALUES (?, ?, ?)');
    foreach ($links->fetchAll() as $row) {
        $ins->execute([(int) $row['document_id'], $keep, $row['role']]);
    }
    $pdo->prepare('DELETE FROM document_entities WHERE entity_id = ?')->execute([$drop]);
    $pdo->prepare('DELETE FROM entities WHERE id = ?')->execute([$drop]);
}

function seed_all_deadlines(PDO $pdo): void
{
    $n = (int) $pdo->query('SELECT COUNT(*) FROM deadlines')->fetchColumn();
    if ($n > 0) {
        return;
    }
    $docs = $pdo->query("SELECT id, title, extra_json FROM documents WHERE extra_json IS NOT NULL AND review_status <> 'out_of_scope'")->fetchAll();
    foreach ($docs as $doc) {
        $extra = extra_decode($doc['extra_json'] ?? null);
        if ($extra) {
            seed_deadlines_from_document($pdo, (int) $doc['id'], $extra, (string) ($doc['title'] ?? ''));
        }
    }
}

/** @return array<string, array{prompt:string, options:list<string>}> */
function untrusted_option_seeds(): array
{
    return [
        '5|due_date' => [
            'prompt' => 'What is the Pasco water bill due date?',
            'options' => ['October 16, 2025', "Can't read it on the photo"],
        ],
        '5|bill_date' => [
            'prompt' => 'What is the Pasco water bill date?',
            'options' => ['October 1, 2025', "Can't tell from the photo"],
        ],
        '6|lease_start' => [
            'prompt' => 'When did the 7312 Windsor lease start?',
            'options' => ['September 17, 2025 (printed on the lease)', 'September 10, 2025 (in the motion/answer)'],
        ],
        '6|lease_end' => [
            'prompt' => 'When did the 7312 Windsor lease end?',
            'options' => ['August 31, 2026 (printed on the lease)', 'September 1, 2026 (in the motion/answer)'],
        ],
        '10|vehicle_make_model' => [
            'prompt' => 'What car was sold on the NY bill of sale?',
            'options' => ['2012 Hyundai Accent (VIN KMHCT… typically Accent)', '2012 Hyundai Accord (as printed on the bill)'],
        ],
        '13|document_subject' => [
            'prompt' => 'What is the April 5, 2025 scan?',
            'options' => ['Court / legal', 'Housing', 'Medical', 'Receipt / bill', 'Not needed / junk'],
        ],
        '14|document_subject' => [
            'prompt' => 'What is the May 8, 2014 scan?',
            'options' => ['Court / legal', 'Housing', 'Medical', 'Receipt / bill', 'Not needed / junk'],
        ],
        '15|served_on' => [
            'prompt' => 'Was the July 9 demand-to-vacate letter served on Olivia?',
            'options' => ['Yes, it was served', 'No, it was never served', 'Draft only / not served yet'],
        ],
        '15|damages_total' => [
            'prompt' => 'Are the $1,706 itemized damages in the Olivia letter accurate?',
            'options' => ['Yes, $1,706 is right', 'Do not treat those numbers as true'],
        ],
        '16|case_number_format' => [
            'prompt' => 'How should the March 2026 case number be written?',
            'options' => ['2026SC1800WS (as typed on the draft)', '2026-SC-1800-WS', 'Same case as 2026-CC-6082-WS'],
        ],
        '16|was_filed' => [
            'prompt' => 'Was the March 26, 2026 motion to quash actually filed?',
            'options' => ['Yes, it was filed', 'No, draft only', "Don't know"],
        ],
        '17|was_filed' => [
            'prompt' => 'Was the March 26, 2026 pretrial statement actually filed?',
            'options' => ['Yes, it was filed', 'No, draft only', "Don't know"],
        ],
        '18|text_date_year' => [
            'prompt' => 'What year is the Roy text screenshot (Thursday Jan 15, 2:38 PM)?',
            'options' => ['January 15, 2026', 'January 15, 2025'],
        ],
        '19|amount_and_parties' => [
            'prompt' => 'Is the promissory note a real signed note?',
            'options' => ['Blank template only', 'It was filled in / signed'],
        ],
        '20|letter_date' => [
            'prompt' => 'What date is the Springbrook Fair Treatment letter?',
            'options' => ['May 19, 2018 (printed on the letter)', 'May 20, 2018 (from the filename)'],
        ],
        '21|case_details' => [
            'prompt' => 'Do you have a docket number for the 2024 Oneonta arson charge?',
            'options' => ['No docket number — leave it blank', 'Ignore / not this charge'],
        ],
        '21|olivia_alternative_cause' => [
            'prompt' => 'How should we treat the Olivia-as-cause theory in the arson memo?',
            'options' => ["Keep it as Mike's theory, not a finding", 'Treat it as established fact', 'Remove it'],
        ],
        '22|extra_occupant_demand' => [
            'prompt' => 'Did the Windsor landlord demand extra rent per occupant?',
            'options' => ['Yes, about $2,000 per extra person', 'No extra-person charge'],
        ],
        '23|year' => [
            'prompt' => 'What year is the pets/move to-do list?',
            'options' => ['2025', '2026', 'Not sure / mixed'],
        ],
        '23|danny_eviction_hold' => [
            'prompt' => 'Who is “Danny” on the eviction-hold to-do?',
            'options' => ['Daniel Bredin (Celtic Motel)', 'Ignore this task'],
        ],
        '24|executed' => [
            'prompt' => 'Is the Celtic Motel master lease a signed agreement?',
            'options' => ['Signed / in effect', 'Unsigned draft only', "Don't know"],
        ],
        '25|medication_list' => [
            'prompt' => 'Is there a real medication list besides the empty sheet?',
            'options' => ['No — the sheet is empty on purpose', "I'll add the real list later"],
        ],
        '26|listing_currency_mixed' => [
            'prompt' => 'What are the Lutz FL Living rows?',
            'options' => ['Mix of rentals and homes for sale', 'All rentals', 'All for sale'],
        ],
        '2|case_number' => [
            'prompt' => 'What is the eviction summons case number?',
            'options' => ['2026-CC-6082-WS', '2026CC100608WS', '2026SC1800WS'],
        ],
    ];
}

function seed_untrusted_options(PDO $pdo): void
{
    $optCol = $pdo->query("SHOW COLUMNS FROM untrusted_facts LIKE 'options_json'")->fetch();
    if (!$optCol) {
        return;
    }
    $seeds = untrusted_option_seeds();
    $rows = $pdo->query(
        'SELECT id, document_id, fact_key, options_json FROM untrusted_facts'
    )->fetchAll();
    $upd = $pdo->prepare(
        'UPDATE untrusted_facts SET prompt = ?, options_json = ? WHERE id = ? AND (options_json IS NULL OR options_json = \'\')'
    );
    foreach ($rows as $row) {
        $key = (string) $row['document_id'] . '|' . (string) $row['fact_key'];
        if (!isset($seeds[$key])) {
            continue;
        }
        $upd->execute([
            $seeds[$key]['prompt'],
            json_encode($seeds[$key]['options'], JSON_UNESCAPED_UNICODE),
            (int) $row['id'],
        ]);
    }
}

function ensure_named_entity(PDO $pdo, string $kind, string $name, ?string $notes = null): int
{
    $stmt = $pdo->prepare('SELECT id, notes FROM entities WHERE kind = ? AND name = ? LIMIT 1');
    $stmt->execute([$kind, $name]);
    $row = $stmt->fetch();
    if ($row) {
        $id = (int) $row['id'];
        if ($notes && (trim((string) $row['notes']) === '' || !str_contains((string) $row['notes'], $notes))) {
            $pdo->prepare(
                "UPDATE entities SET notes = CONCAT(IFNULL(notes,''), IF(IFNULL(notes,'')='','','\n\n'), ?) WHERE id = ?"
            )->execute([$notes, $id]);
        }
        return $id;
    }
    $pdo->prepare('INSERT INTO entities (kind, name, notes) VALUES (?, ?, ?)')->execute([$kind, $name, $notes]);
    return (int) $pdo->lastInsertId();
}

function seed_journal_2026_08_22(PDO $pdo): void
{
    if (!table_exists($pdo, 'journals')) {
        return;
    }
    $exists = $pdo->prepare('SELECT id FROM journals WHERE entry_date = ? LIMIT 1');
    $exists->execute(['2026-08-22']);
    if ($exists->fetch()) {
        return;
    }
    $body = <<<'TXT'
Hey, I'm Michael. This will be my first dictated journal entry. I'm writing this today on Saturday, August 22nd, 2026 at about 5:40 PM. Today was a pretty good day. I woke up around 6 AM to drive Olivia to work. Olivia works at Waverly Assisted Living. After I got back home around 7 AM, I got in the sauna for about an hour and took my morning supplements and medication. I try to weigh myself most mornings. And I'd like to maybe start keeping a timeline of my weight. But my weight this morning was 196.9 pounds. And when I take my weight, I'm naked. And it is before eating or drinking anything for the day. I took my dogs for a walk this morning as well. And I started organizing to get ready to move by the end of the month. I'm trying to organize all of my stuff. So anything that I do not need within 30 days, I'm trying to pack up by the end of the weekend here. And get it to a new storage locker I'm going to set up on Monday. That way I'll be moving much lighter at the very end of the month. I'm pretty excited about the document organizer that we're building here, which is really going to become a life organizer for me. I've started taking lots of pictures and soon I'll be uploading even more documents so that we can create a really strong database with all of my information. I'm going to try to do these journal entries every day and allow Grok to read my journal entry and take any pertinent information and create wiki entity pages that are needed that link back to the journal. And the journal entry itself should also be stored in the SQL database because I'm going to have these journal entries every day. And I'm eventually going to ask you for some insight on what we should do next based on my dictations.
TXT;
    $pdo->prepare(
        'INSERT INTO journals (entry_date, entry_at, title, body, source) VALUES (?, ?, ?, ?, ?)'
    )->execute(['2026-08-22', '2026-08-22 17:40:00', 'First dictated journal', $body, 'dictation']);
    $jid = (int) $pdo->lastInsertId();

    $mike = ensure_named_entity($pdo, 'person', 'Michael David Bredin', 'Morning routine (journal 2026-08-22): sauna ~1 hour after 7 AM, supplements and medication, fasted naked weigh-in, dog walk. Packing 30-day-not-needed items for a Monday storage locker; wants to move lighter by end of August 2026.');
    $olivia = ensure_named_entity($pdo, 'person', 'Olivia Lazarus', 'Works at Waverly Assisted Living (journal 2026-08-22). Michael drove her to work around 6 AM that day.');
    $waverly = ensure_named_entity($pdo, 'org', 'Waverly Assisted Living', 'Olivia\'s workplace. Mentioned 2026-08-22.');
    $dogs = ensure_named_entity($pdo, 'asset', 'Dogs', 'Walked the morning of 2026-08-22. Individual names not listed in that entry.');
    $locker = ensure_named_entity($pdo, 'place', 'Storage locker (new, Monday setup)', 'Michael planned to set up a new storage locker on Monday 2026-08-24 for anything not needed within 30 days, so the end-of-month move is lighter.');
    $move = ensure_named_entity($pdo, 'place', '7312 Windsor St, Hudson, FL 34667', 'Packing to move by end of August 2026 (journal 2026-08-22).');

    $link = $pdo->prepare('INSERT IGNORE INTO journal_entities (journal_id, entity_id, role) VALUES (?, ?, ?)');
    $link->execute([$jid, $mike, 'author']);
    $link->execute([$jid, $olivia, 'mentions']);
    $link->execute([$jid, $waverly, 'workplace']);
    $link->execute([$jid, $dogs, 'mentions']);
    $link->execute([$jid, $locker, 'plan']);
    $link->execute([$jid, $move, 'home']);

    $pdo->prepare(
        'INSERT INTO measurements (taken_on, taken_at, kind, value_num, unit, conditions, journal_id, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        '2026-08-22', null, 'weight', 196.9, 'lb',
        'naked, fasted (before food or drink), morning',
        $jid, 'Self-reported in first dictated journal',
    ]);

    if (table_exists($pdo, 'deadlines')) {
        $dl = $pdo->prepare(
            'INSERT INTO deadlines (document_id, case_id, entity_id, due_on, due_at, kind, title, status, source_key)
             VALUES (NULL, NULL, ?, ?, NULL, ?, ?, \'open\', ?)'
        );
        $have = $pdo->prepare('SELECT id FROM deadlines WHERE source_key = ? LIMIT 1');
        $rows = [
            ['journal:pack-30-day', $mike, '2026-08-23', 'other', 'Pack anything not needed in 30 days (weekend)'],
            ['journal:storage-locker', $locker, '2026-08-24', 'other', 'Set up new storage locker'],
            ['journal:move-eom', $move, '2026-08-31', 'other', 'Move lighter by end of August'],
        ];
        foreach ($rows as $r) {
            $have->execute([$r[0]]);
            if (!$have->fetch()) {
                $dl->execute([$r[1], $r[2], $r[3], $r[4], $r[0]]);
            }
        }
    }
}

function request_source(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 'phone' : 'upload';
}

require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/cabinet.php';
require_once __DIR__ . '/life.php';
require_once __DIR__ . '/fintable.php';
require_once __DIR__ . '/budget.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/habits.php';
require_once __DIR__ . '/meds.php';


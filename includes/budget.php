<?php

declare(strict_types=1);

function migrate_money_schema(PDO $pdo): void
{
    if (!table_exists($pdo, 'budget_categories')) {
        $pdo->exec(
            "CREATE TABLE budget_categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(32) NOT NULL,
                name VARCHAR(80) NOT NULL,
                kind VARCHAR(16) NOT NULL DEFAULT 'expense',
                sort_order INT UNSIGNED NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                keywords TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_bc_slug (slug)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    if (!table_exists($pdo, 'budget_lines')) {
        $pdo->exec(
            "CREATE TABLE budget_lines (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                month_key CHAR(7) NOT NULL,
                category_id INT UNSIGNED NOT NULL,
                planned DECIMAL(10,2) NOT NULL DEFAULT 0,
                UNIQUE KEY uq_bl_month_cat (month_key, category_id),
                INDEX idx_bl_month (month_key)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    if (!table_exists($pdo, 'money_rules')) {
        $pdo->exec(
            "CREATE TABLE money_rules (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                match_kind VARCHAR(32) NOT NULL DEFAULT 'merchant',
                match_value VARCHAR(180) NOT NULL,
                category_id INT UNSIGNED NULL,
                recurring_bill_id INT UNSIGNED NULL,
                is_transfer TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_mr_kind (match_kind)
            ) DEFAULT CHARSET=utf8mb4"
        );
    }
    if (table_exists($pdo, 'recurring_bills') && !column_exists($pdo, 'recurring_bills', 'category_id')) {
        $pdo->exec('ALTER TABLE recurring_bills ADD COLUMN category_id INT UNSIGNED NULL AFTER entity_id');
    }
    if (table_exists($pdo, 'bank_transactions')) {
        if (!column_exists($pdo, 'bank_transactions', 'budget_category_id')) {
            $pdo->exec('ALTER TABLE bank_transactions ADD COLUMN budget_category_id INT UNSIGNED NULL');
        }
        if (!column_exists($pdo, 'bank_transactions', 'recurring_bill_id')) {
            $pdo->exec('ALTER TABLE bank_transactions ADD COLUMN recurring_bill_id INT UNSIGNED NULL');
        }
        if (!column_exists($pdo, 'bank_transactions', 'is_transfer')) {
            $pdo->exec('ALTER TABLE bank_transactions ADD COLUMN is_transfer TINYINT(1) NOT NULL DEFAULT 0');
        }
        if (!column_exists($pdo, 'bank_transactions', 'review_status')) {
            $pdo->exec("ALTER TABLE bank_transactions ADD COLUMN review_status VARCHAR(16) NOT NULL DEFAULT 'open'");
        }
    }
    if (table_exists($pdo, 'untrusted_facts') && !column_exists($pdo, 'untrusted_facts', 'transaction_id')) {
        $pdo->exec('ALTER TABLE untrusted_facts ADD COLUMN transaction_id INT UNSIGNED NULL AFTER journal_id');
        $pdo->exec('ALTER TABLE untrusted_facts ADD INDEX idx_uf_txn (transaction_id)');
    }
    seed_budget_categories($pdo);
    ensure_budget_month($pdo, date('Y-m'));
}

function seed_budget_categories(PDO $pdo): void
{
    if (!table_exists($pdo, 'budget_categories')) {
        return;
    }
    $rows = [
        ['housing', 'Housing / rent', 'expense', 10, 'rent,housing,mortgage,landlord,windsor'],
        ['utilities', 'Utilities', 'expense', 20, 'utilities,water,electric,gas bill,internet,pasco'],
        ['groceries', 'Groceries', 'expense', 30, 'groceries,supermarket,grocery'],
        ['dining', 'Dining', 'expense', 40, 'restaurants,dining,fast food,coffee'],
        ['transport', 'Transport', 'expense', 50, 'gas,uber,lyft,transit,automotive,parking'],
        ['medical', 'Medical', 'expense', 60, 'medical,pharmacy,healthcare,doctor'],
        ['legal', 'Legal / court', 'expense', 70, 'legal,court,attorney'],
        ['debt', 'Debt / cards', 'expense', 80, 'loan,credit card payment'],
        ['subscriptions', 'Subscriptions', 'expense', 90, 'subscription,software,streaming'],
        ['storage', 'Storage / move', 'expense', 100, 'storage,u-haul,moving'],
        ['personal', 'Personal', 'expense', 110, 'personal,shopping,general'],
        ['other', 'Other', 'expense', 120, ''],
        ['pay', 'Pay', 'income', 200, 'paycheck,payroll,direct deposit,salary,income'],
        ['other_income', 'Other income', 'income', 210, 'refund,deposit'],
        ['transfer', 'Transfer', 'transfer', 300, 'transfer,internal,payment to card'],
    ];
    $ins = $pdo->prepare(
        'INSERT INTO budget_categories (slug, name, kind, sort_order, keywords)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), kind = VALUES(kind), sort_order = VALUES(sort_order), keywords = VALUES(keywords)'
    );
    foreach ($rows as $r) {
        $ins->execute($r);
    }
}

function budget_category_id(PDO $pdo, string $slug): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM budget_categories WHERE slug = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$slug]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/** @return list<array<string,mixed>> */
function budget_categories(PDO $pdo, ?string $kind = null): array
{
    if (!table_exists($pdo, 'budget_categories')) {
        return [];
    }
    if ($kind) {
        $stmt = $pdo->prepare("SELECT * FROM budget_categories WHERE status = 'active' AND kind = ? ORDER BY sort_order, id");
        $stmt->execute([$kind]);
        return $stmt->fetchAll();
    }
    return $pdo->query("SELECT * FROM budget_categories WHERE status = 'active' ORDER BY sort_order, id")->fetchAll();
}

function ensure_budget_month(PDO $pdo, string $ym): void
{
    if (!preg_match('/^\d{4}-\d{2}$/', $ym) || !table_exists($pdo, 'budget_lines')) {
        return;
    }
    $cats = budget_categories($pdo);
    $ins = $pdo->prepare(
        'INSERT IGNORE INTO budget_lines (month_key, category_id, planned) VALUES (?, ?, 0)'
    );
    foreach ($cats as $c) {
        if (($c['kind'] ?? '') === 'transfer') {
            continue;
        }
        $ins->execute([$ym, (int) $c['id']]);
    }
}

function money_month_param(): string
{
    $m = (string) ($_GET['month'] ?? $_POST['month'] ?? date('Y-m'));
    return preg_match('/^\d{4}-\d{2}$/', $m) ? $m : date('Y-m');
}

function money_month_shift(string $ym, int $delta): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01') ?: new DateTimeImmutable('first day of this month');
    return $dt->modify(($delta >= 0 ? '+' : '') . $delta . ' month')->format('Y-m');
}

function money_month_label(string $ym): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01');
    return $dt ? $dt->format('F Y') : $ym;
}

function doc_type_budget_slug(?string $type): ?string
{
    return match ($type) {
        'housing_lease', 'housing_notice' => 'housing',
        'utility_bill' => 'utilities',
        'medical_bill', 'medical_record' => 'medical',
        'court_summons', 'court_filing' => 'legal',
        'bank_letter' => 'debt',
        default => null,
    };
}

function money_plain(?float $n): string
{
    if ($n === null) {
        return '—';
    }
    $sign = $n < 0 ? '-' : '';
    return $sign . '$' . number_format(abs($n), 2);
}

/**
 * @return array<int, array{planned:float, spent:float, received:float}>
 */
function budget_rollups(PDO $pdo, string $ym): array
{
    $out = [];
    if (!table_exists($pdo, 'budget_lines')) {
        return $out;
    }
    $lines = $pdo->prepare('SELECT category_id, planned FROM budget_lines WHERE month_key = ?');
    $lines->execute([$ym]);
    foreach ($lines->fetchAll() as $row) {
        $out[(int) $row['category_id']] = [
            'planned' => (float) $row['planned'],
            'spent' => 0.0,
            'received' => 0.0,
        ];
    }
    if (!table_exists($pdo, 'bank_transactions')) {
        return $out;
    }
    $start = $ym . '-01';
    $end = money_month_shift($ym, 1) . '-01';
    $sql = "SELECT COALESCE(budget_category_id, 0) AS cid, amount
            FROM bank_transactions
            WHERE posted_on >= ? AND posted_on < ?
              AND COALESCE(is_transfer, 0) = 0
              AND COALESCE(review_status, 'open') <> 'ignored'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start, $end]);
    foreach ($stmt->fetchAll() as $row) {
        $cid = (int) $row['cid'];
        if (!isset($out[$cid])) {
            $out[$cid] = ['planned' => 0.0, 'spent' => 0.0, 'received' => 0.0];
        }
        $amt = (float) $row['amount'];
        if ($amt < 0) {
            $out[$cid]['spent'] += abs($amt);
        } else {
            $out[$cid]['received'] += $amt;
        }
    }
    return $out;
}

function budget_totals(array $cats, array $roll): array
{
    $plan = 0.0;
    $spent = 0.0;
    $incomePlan = 0.0;
    $incomeGot = 0.0;
    $unsorted = 0.0;
    foreach ($cats as $c) {
        $id = (int) $c['id'];
        $r = $roll[$id] ?? ['planned' => 0.0, 'spent' => 0.0, 'received' => 0.0];
        if (($c['kind'] ?? '') === 'income') {
            $incomePlan += $r['planned'];
            $incomeGot += $r['received'];
        } elseif (($c['kind'] ?? '') === 'expense') {
            $plan += $r['planned'];
            $spent += $r['spent'];
        }
    }
    $unsorted = ($roll[0]['spent'] ?? 0.0);
    return [
        'planned' => $plan,
        'spent' => $spent,
        'left' => $plan - $spent,
        'income_plan' => $incomePlan,
        'income_got' => $incomeGot,
        'unsorted' => $unsorted,
    ];
}

function cash_on_hand(PDO $pdo): ?float
{
    if (!table_exists($pdo, 'bank_accounts')) {
        return null;
    }
    $n = (int) $pdo->query('SELECT COUNT(*) FROM bank_accounts WHERE enabled = 1')->fetchColumn();
    if ($n < 1) {
        return null;
    }
    $checking = $pdo->query(
        "SELECT SUM(balance) FROM bank_accounts
         WHERE enabled = 1 AND (type LIKE '%checking%' OR type LIKE '%depository%')"
    )->fetchColumn();
    if ($checking !== null && $checking !== false && (float) $checking != 0.0) {
        return (float) $checking;
    }
    $all = $pdo->query('SELECT SUM(balance) FROM bank_accounts WHERE enabled = 1')->fetchColumn();
    return $all === null || $all === false ? null : (float) $all;
}

function unpaid_bills_soon(PDO $pdo, int $days = 32): float
{
    if (!table_exists($pdo, 'recurring_bills')) {
        return 0.0;
    }
    $until = date('Y-m-d', strtotime('+' . $days . ' days'));
    $rows = $pdo->query("SELECT * FROM recurring_bills WHERE status = 'active' AND amount IS NOT NULL")->fetchAll();
    $sum = 0.0;
    foreach ($rows as $b) {
        if (bill_is_paid_this_cycle($pdo, $b)) {
            continue;
        }
        $due = (string) $b['next_due'];
        if ($due <= $until) {
            $sum += (float) $b['amount'];
        }
    }
    return $sum;
}

function bill_cycle_start(array $bill): string
{
    $due = (string) ($bill['next_due'] ?? date('Y-m-d'));
    $dom = (int) ($bill['day_of_month'] ?: substr($due, 8, 2));
    return date('Y-m-d', strtotime($due . ' -40 days'));
}

function bill_is_paid_this_cycle(PDO $pdo, array $bill): bool
{
    if (!table_exists($pdo, 'bank_transactions')) {
        return false;
    }
    $id = (int) $bill['id'];
    $start = bill_cycle_start($bill);
    $end = date('Y-m-d', strtotime((string) $bill['next_due'] . ' +10 days'));
    $stmt = $pdo->prepare(
        'SELECT id FROM bank_transactions
         WHERE recurring_bill_id = ? AND posted_on >= ? AND posted_on <= ? LIMIT 1'
    );
    $stmt->execute([$id, $start, $end]);
    return (bool) $stmt->fetchColumn();
}

function bill_matched_txn(PDO $pdo, array $bill): ?array
{
    if (!table_exists($pdo, 'bank_transactions')) {
        return null;
    }
    $start = bill_cycle_start($bill);
    $end = date('Y-m-d', strtotime((string) $bill['next_due'] . ' +10 days'));
    $stmt = $pdo->prepare(
        'SELECT * FROM bank_transactions
         WHERE recurring_bill_id = ? AND posted_on >= ? AND posted_on <= ?
         ORDER BY posted_on DESC LIMIT 1'
    );
    $stmt->execute([(int) $bill['id'], $start, $end]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function payee_tokens(string $payee): array
{
    $stop = ['llc', 'inc', 'ltd', 'the', 'and', 'of', 'county', 'utilities', 'company', 'co'];
    $parts = preg_split('/[^a-z0-9]+/i', strtolower($payee)) ?: [];
    $out = [];
    foreach ($parts as $p) {
        if (strlen($p) < 4 || in_array($p, $stop, true)) {
            continue;
        }
        $out[] = $p;
    }
    return $out;
}

function txn_haystack(array $txn): string
{
    return strtolower(trim((string) ($txn['merchant'] ?? '') . ' ' . (string) ($txn['description'] ?? '') . ' ' . (string) ($txn['category'] ?? '')));
}

function amount_close(float $billAmt, float $txnAmt): bool
{
    $a = abs($billAmt);
    $t = abs($txnAmt);
    if ($a <= 0) {
        return false;
    }
    return abs($a - $t) <= max(1.0, $a * 0.03);
}

function assign_transaction(PDO $pdo, int $txnId, ?int $categoryId, ?int $billId, bool $transfer = false): void
{
    if ($txnId < 1 || !table_exists($pdo, 'bank_transactions')) {
        return;
    }
    $status = ($categoryId || $billId || $transfer) ? 'assigned' : 'open';
    $pdo->prepare(
        'UPDATE bank_transactions
         SET budget_category_id = ?, recurring_bill_id = ?, is_transfer = ?, review_status = ?
         WHERE id = ?'
    )->execute([
        $categoryId,
        $billId,
        $transfer ? 1 : 0,
        $transfer ? 'assigned' : $status,
        $txnId,
    ]);
}

function add_money_rule(PDO $pdo, string $kind, string $value, ?int $categoryId, ?int $billId = null, bool $transfer = false): void
{
    $value = mb_substr(trim($value), 0, 180);
    if ($value === '') {
        return;
    }
    $have = $pdo->prepare('SELECT id FROM money_rules WHERE match_kind = ? AND match_value = ? LIMIT 1');
    $have->execute([$kind, $value]);
    if ($have->fetch()) {
        $pdo->prepare(
            'UPDATE money_rules SET category_id = ?, recurring_bill_id = ?, is_transfer = ? WHERE match_kind = ? AND match_value = ?'
        )->execute([$categoryId, $billId, $transfer ? 1 : 0, $kind, $value]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO money_rules (match_kind, match_value, category_id, recurring_bill_id, is_transfer)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$kind, $value, $categoryId, $billId, $transfer ? 1 : 0]);
}

function apply_money_rules(PDO $pdo): int
{
    if (!table_exists($pdo, 'bank_transactions') || !table_exists($pdo, 'money_rules')) {
        return 0;
    }
    $rules = $pdo->query('SELECT * FROM money_rules ORDER BY id')->fetchAll();
    $cats = budget_categories($pdo);
    $keywordMap = [];
    foreach ($cats as $c) {
        foreach (explode(',', (string) ($c['keywords'] ?? '')) as $kw) {
            $kw = strtolower(trim($kw));
            if ($kw !== '') {
                $keywordMap[$kw] = (int) $c['id'];
            }
        }
    }
    $txns = $pdo->query(
        "SELECT * FROM bank_transactions
         WHERE budget_category_id IS NULL AND COALESCE(is_transfer,0)=0 AND COALESCE(review_status,'open') <> 'ignored'"
    )->fetchAll();
    $n = 0;
    foreach ($txns as $txn) {
        $hay = txn_haystack($txn);
        $hit = false;
        foreach ($rules as $rule) {
            $needle = strtolower((string) $rule['match_value']);
            if ($needle === '') {
                continue;
            }
            $kind = (string) $rule['match_kind'];
            $ok = false;
            if ($kind === 'merchant' && str_contains(strtolower((string) ($txn['merchant'] ?? '')), $needle)) {
                $ok = true;
            } elseif ($kind === 'description' && str_contains($hay, $needle)) {
                $ok = true;
            } elseif ($kind === 'fintable_category' && str_contains(strtolower((string) ($txn['category'] ?? '')), $needle)) {
                $ok = true;
            } elseif ($kind === 'payee' && str_contains($hay, $needle)) {
                $ok = true;
            }
            if (!$ok) {
                continue;
            }
            assign_transaction(
                $pdo,
                (int) $txn['id'],
                $rule['category_id'] ? (int) $rule['category_id'] : null,
                $rule['recurring_bill_id'] ? (int) $rule['recurring_bill_id'] : null,
                (bool) $rule['is_transfer']
            );
            $n++;
            $hit = true;
            break;
        }
        if ($hit) {
            continue;
        }
        $ft = strtolower((string) ($txn['category'] ?? ''));
        if ($ft === '') {
            continue;
        }
        foreach ($keywordMap as $kw => $cid) {
            if (str_contains($ft, $kw) || str_contains($hay, $kw)) {
                $cat = null;
                foreach ($cats as $c) {
                    if ((int) $c['id'] === $cid) {
                        $cat = $c;
                        break;
                    }
                }
                $transfer = $cat && ($cat['kind'] ?? '') === 'transfer';
                assign_transaction($pdo, (int) $txn['id'], $cid, null, $transfer);
                $n++;
                break;
            }
        }
    }
    return $n;
}

function propose_bill_matches(PDO $pdo): int
{
    if (!table_exists($pdo, 'recurring_bills') || !table_exists($pdo, 'bank_transactions') || !table_exists($pdo, 'untrusted_facts')) {
        return 0;
    }
    if (!column_exists($pdo, 'untrusted_facts', 'transaction_id')) {
        return 0;
    }
    $bills = $pdo->query("SELECT * FROM recurring_bills WHERE status = 'active' AND amount IS NOT NULL")->fetchAll();
    $n = 0;
    foreach ($bills as $bill) {
        if (bill_is_paid_this_cycle($pdo, $bill)) {
            continue;
        }
        $start = bill_cycle_start($bill);
        $end = date('Y-m-d', strtotime((string) $bill['next_due'] . ' +10 days'));
        $txns = $pdo->prepare(
            "SELECT * FROM bank_transactions
             WHERE posted_on >= ? AND posted_on <= ?
               AND recurring_bill_id IS NULL
               AND COALESCE(is_transfer,0)=0
               AND COALESCE(review_status,'open') <> 'ignored'
             ORDER BY posted_on DESC"
        );
        $txns->execute([$start, $end]);
        $tokens = payee_tokens((string) $bill['payee']);
        $billAmt = (float) $bill['amount'];
        foreach ($txns->fetchAll() as $txn) {
            if (!amount_close($billAmt, (float) $txn['amount'])) {
                continue;
            }
            $hay = txn_haystack($txn);
            $nameHit = false;
            foreach ($tokens as $tok) {
                if (str_contains($hay, $tok)) {
                    $nameHit = true;
                    break;
                }
            }
            $exists = $pdo->prepare(
                "SELECT id FROM untrusted_facts
                 WHERE transaction_id = ? AND fact_key = 'bill_match' AND status IN ('open','confirmed') LIMIT 1"
            );
            $exists->execute([(int) $txn['id']]);
            if ($exists->fetch()) {
                continue;
            }
            $label = trim((string) ($txn['merchant'] ?: $txn['description'] ?: 'Transaction'));
            $payload = extra_encode([
                'transaction_id' => (int) $txn['id'],
                'bill_id' => (int) $bill['id'],
                'category_id' => $bill['category_id'] ? (int) $bill['category_id'] : null,
                'merchant' => $label,
            ]);
            $prompt = 'Is ' . money_plain((float) $txn['amount']) . ' ' . mb_substr($label, 0, 40) . ' the ' . $bill['payee'] . ' bill?';
            $reason = $nameHit
                ? 'Amount and name look like this bill'
                : 'Amount matches this bill; name is unclear';
            $pdo->prepare(
                'INSERT INTO untrusted_facts
                 (document_id, journal_id, transaction_id, fact_key, fact_value, prompt, options_json, reason, importance, status)
                 VALUES (NULL, NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                (int) $txn['id'],
                'bill_match',
                $payload,
                mb_substr($prompt, 0, 180),
                extra_encode(['Yes — that bill', 'Skip']),
                mb_substr($reason, 0, 255),
                $nameHit ? 'important' : 'normal',
                'open',
            ]);
            $n++;
            break;
        }
    }
    return $n;
}

function refresh_money_after_sync(PDO $pdo): void
{
    apply_money_rules($pdo);
    propose_bill_matches($pdo);
}

function confirm_bill_match(PDO $pdo, array $fact, string $chosen): void
{
    if (stripos($chosen, 'skip') !== false || fact_is_non_answer($chosen)) {
        return;
    }
    $payload = extra_decode((string) ($fact['fact_value'] ?? ''));
    $txnId = (int) ($payload['transaction_id'] ?? $fact['transaction_id'] ?? 0);
    $billId = (int) ($payload['bill_id'] ?? 0);
    $catId = isset($payload['category_id']) && $payload['category_id'] ? (int) $payload['category_id'] : null;
    if ($txnId < 1 || $billId < 1) {
        return;
    }
    if ($catId === null) {
        $bill = $pdo->prepare('SELECT category_id FROM recurring_bills WHERE id = ?');
        $bill->execute([$billId]);
        $c = $bill->fetchColumn();
        $catId = $c ? (int) $c : null;
    }
    assign_transaction($pdo, $txnId, $catId, $billId, false);
    $txn = $pdo->prepare('SELECT merchant FROM bank_transactions WHERE id = ?');
    $txn->execute([$txnId]);
    $merchant = trim((string) $txn->fetchColumn());
    if ($merchant !== '') {
        add_money_rule($pdo, 'merchant', $merchant, $catId, $billId, false);
    }
}

function confirm_txn_category(PDO $pdo, array $fact, string $chosen): void
{
    if (stripos($chosen, 'skip') !== false || fact_is_non_answer($chosen)) {
        return;
    }
    $payload = extra_decode((string) ($fact['fact_value'] ?? ''));
    $txnId = (int) ($payload['transaction_id'] ?? $fact['transaction_id'] ?? 0);
    $catId = (int) ($payload['category_id'] ?? 0);
    if ($txnId < 1) {
        return;
    }
    $slug = strtolower(trim($chosen));
    if ($catId < 1 && $slug !== '' && $slug !== 'yes') {
        $catId = budget_category_id($pdo, $slug) ?? 0;
        $byName = $pdo->prepare('SELECT id FROM budget_categories WHERE name = ? LIMIT 1');
        $byName->execute([$chosen]);
        $catId = $catId ?: (int) $byName->fetchColumn();
    }
    if ($catId < 1) {
        return;
    }
    $cat = $pdo->prepare('SELECT kind FROM budget_categories WHERE id = ?');
    $cat->execute([$catId]);
    $kind = (string) $cat->fetchColumn();
    assign_transaction($pdo, $txnId, $catId, null, $kind === 'transfer');
}

function save_budget_plan(PDO $pdo, string $ym, array $planned): void
{
    ensure_budget_month($pdo, $ym);
    $upd = $pdo->prepare('UPDATE budget_lines SET planned = ? WHERE month_key = ? AND category_id = ?');
    foreach ($planned as $cid => $amt) {
        $cid = (int) $cid;
        if ($cid < 1) {
            continue;
        }
        $n = is_numeric($amt) ? round((float) $amt, 2) : 0.0;
        if ($n < 0) {
            $n = 0.0;
        }
        $upd->execute([$n, $ym, $cid]);
    }
}

function copy_budget_from_previous(PDO $pdo, string $ym): void
{
    $prev = money_month_shift($ym, -1);
    ensure_budget_month($pdo, $ym);
    ensure_budget_month($pdo, $prev);
    $pdo->prepare(
        "UPDATE budget_lines cur
         JOIN budget_lines old ON old.category_id = cur.category_id AND old.month_key = ?
         SET cur.planned = old.planned
         WHERE cur.month_key = ? AND cur.planned = 0 AND old.planned <> 0"
    )->execute([$prev, $ym]);
}

function fill_budget_from_bills(PDO $pdo, string $ym): int
{
    ensure_budget_month($pdo, $ym);
    if (!table_exists($pdo, 'recurring_bills')) {
        return 0;
    }
    $stmt = $pdo->query(
        "SELECT category_id, SUM(amount) AS s
         FROM recurring_bills
         WHERE status = 'active' AND amount IS NOT NULL AND category_id IS NOT NULL
         GROUP BY category_id"
    );
    $n = 0;
    $upd = $pdo->prepare(
        'UPDATE budget_lines SET planned = ? WHERE month_key = ? AND category_id = ? AND planned = 0'
    );
    foreach ($stmt->fetchAll() as $row) {
        $upd->execute([(float) $row['s'], $ym, (int) $row['category_id']]);
        $n += $upd->rowCount();
    }
    return $n;
}

function add_budget_category(PDO $pdo, string $name, string $kind): int
{
    $name = mb_substr(trim($name), 0, 80);
    if ($name === '') {
        return 0;
    }
    if (!in_array($kind, ['expense', 'income'], true)) {
        $kind = 'expense';
    }
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '');
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'cat-' . time();
    }
    $slug = mb_substr($slug, 0, 32);
    $max = (int) $pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM budget_categories')->fetchColumn();
    $pdo->prepare(
        'INSERT INTO budget_categories (slug, name, kind, sort_order, status) VALUES (?, ?, ?, ?, ?)'
    )->execute([$slug, $name, $kind, $max + 10, 'active']);
    $id = (int) $pdo->lastInsertId();
    ensure_budget_month($pdo, date('Y-m'));
    return $id;
}

function money_view(): string
{
    $v = (string) ($_GET['view'] ?? 'overview');
    return in_array($v, ['overview', 'budget', 'bills', 'txns', 'rules'], true) ? $v : 'overview';
}

function render_money_subnav(string $view, string $ym): void
{
    $q = '&month=' . rawurlencode($ym);
    $items = [
        'overview' => 'This month',
        'budget' => 'Budget',
        'bills' => 'Bills',
        'txns' => 'Activity',
        'rules' => 'Rules',
    ];
    echo '<nav class="money-subnav">';
    foreach ($items as $k => $label) {
        $href = 'money.php?view=' . $k . $q;
        echo '<a class="' . ($view === $k ? 'on' : '') . '" href="' . h($href) . '">' . h($label) . '</a>';
    }
    echo '</nav>';
}

function render_money_strip(PDO $pdo): void
{
    if (!table_exists($pdo, 'budget_lines')) {
        return;
    }
    $ym = date('Y-m');
    ensure_budget_month($pdo, $ym);
    $cats = budget_categories($pdo);
    $roll = budget_rollups($pdo, $ym);
    $tot = budget_totals($cats, $roll);
    $openBills = 0;
    if (table_exists($pdo, 'recurring_bills')) {
        foreach ($pdo->query("SELECT * FROM recurring_bills WHERE status = 'active'") as $b) {
            if (!bill_is_paid_this_cycle($pdo, $b)) {
                $openBills++;
            }
        }
    }
    echo '<section class="coming-up money-strip">';
    echo '<h2>Money</h2>';
    if ($tot['planned'] <= 0 && $tot['spent'] <= 0) {
        echo '<p><a href="money.php?view=budget">Set ' . h(money_month_label($ym)) . ' budget</a>';
        echo ' · <a href="money.php?view=bills">Bills</a></p>';
    } else {
        echo '<p><a href="money.php">';
        echo h(money_plain($tot['spent'])) . ' of ' . h(money_plain($tot['planned'])) . ' spent';
        if ($tot['left'] < 0) {
            echo ' · <span class="when">over ' . h(money_plain(abs($tot['left']))) . '</span>';
        } else {
            echo ' · ' . h(money_plain($tot['left'])) . ' left';
        }
        echo '</a>';
        if ($openBills) {
            echo ' · <a href="money.php?view=bills">' . $openBills . ' bill' . ($openBills === 1 ? '' : 's') . ' open</a>';
        }
        if ($tot['unsorted'] > 0) {
            echo ' · <a href="money.php?view=txns">' . h(money_plain($tot['unsorted'])) . ' unsorted</a>';
        }
        echo '</p>';
    }
    echo '</section>';
}

function handle_money_post(PDO $pdo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    $action = (string) ($_POST['action'] ?? '');
    $ym = money_month_param();
    if ($action === 'save_plan') {
        save_budget_plan($pdo, $ym, $_POST['planned'] ?? []);
        header('Location: money.php?view=budget&month=' . rawurlencode($ym) . '&saved=1', true, 303);
        exit;
    }
    if ($action === 'copy_prev') {
        copy_budget_from_previous($pdo, $ym);
        header('Location: money.php?view=budget&month=' . rawurlencode($ym) . '&copied=1', true, 303);
        exit;
    }
    if ($action === 'fill_bills') {
        fill_budget_from_bills($pdo, $ym);
        header('Location: money.php?view=budget&month=' . rawurlencode($ym) . '&filled=1', true, 303);
        exit;
    }
    if ($action === 'add_category') {
        add_budget_category($pdo, (string) ($_POST['name'] ?? ''), (string) ($_POST['kind'] ?? 'expense'));
        header('Location: money.php?view=budget&month=' . rawurlencode($ym), true, 303);
        exit;
    }
    if ($action === 'assign_txn') {
        $txnId = (int) ($_POST['txn_id'] ?? 0);
        $catId = (int) ($_POST['category_id'] ?? 0);
        $billId = (int) ($_POST['bill_id'] ?? 0);
        $always = !empty($_POST['always']);
        if ($txnId > 0 && $catId > 0) {
            $ks = $pdo->prepare('SELECT kind FROM budget_categories WHERE id = ?');
            $ks->execute([$catId]);
            $kind = (string) $ks->fetchColumn();
            assign_transaction($pdo, $txnId, $catId, $billId > 0 ? $billId : null, $kind === 'transfer');
            if ($always) {
                $mer = $pdo->prepare('SELECT merchant, description FROM bank_transactions WHERE id = ?');
                $mer->execute([$txnId]);
                $row = $mer->fetch() ?: [];
                $needle = trim((string) ($row['merchant'] ?? ''));
                if ($needle === '') {
                    $needle = mb_substr((string) ($row['description'] ?? ''), 0, 40);
                }
                if ($needle !== '') {
                    add_money_rule($pdo, 'merchant', $needle, $catId, $billId > 0 ? $billId : null, $kind === 'transfer');
                }
            }
        }
        header('Location: money.php?view=txns&month=' . rawurlencode($ym), true, 303);
        exit;
    }
    if ($action === 'txn_transfer' || $action === 'txn_ignore') {
        $txnId = (int) ($_POST['txn_id'] ?? 0);
        if ($txnId > 0) {
            if ($action === 'txn_transfer') {
                $tid = budget_category_id($pdo, 'transfer');
                assign_transaction($pdo, $txnId, $tid, null, true);
            } else {
                $pdo->prepare("UPDATE bank_transactions SET review_status = 'ignored' WHERE id = ?")->execute([$txnId]);
            }
        }
        header('Location: money.php?view=txns&month=' . rawurlencode($ym), true, 303);
        exit;
    }
    if ($action === 'add_rule') {
        $kind = (string) ($_POST['match_kind'] ?? 'merchant');
        if (!in_array($kind, ['merchant', 'description', 'fintable_category', 'payee'], true)) {
            $kind = 'merchant';
        }
        $catId = (int) ($_POST['category_id'] ?? 0);
        $transfer = false;
        if ($catId > 0) {
            $ks = $pdo->prepare('SELECT kind FROM budget_categories WHERE id = ?');
            $ks->execute([$catId]);
            $transfer = (string) $ks->fetchColumn() === 'transfer';
        }
        add_money_rule($pdo, $kind, (string) ($_POST['match_value'] ?? ''), $catId > 0 ? $catId : null, null, $transfer);
        apply_money_rules($pdo);
        header('Location: money.php?view=rules', true, 303);
        exit;
    }
    if ($action === 'del_rule') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('DELETE FROM money_rules WHERE id = ?')->execute([$id]);
        }
        header('Location: money.php?view=rules', true, 303);
        exit;
    }
    if ($action === 'link_bill_cat') {
        $billId = (int) ($_POST['bill_id'] ?? 0);
        $catId = (int) ($_POST['category_id'] ?? 0);
        if ($billId > 0) {
            $pdo->prepare('UPDATE recurring_bills SET category_id = ? WHERE id = ?')
                ->execute([$catId > 0 ? $catId : null, $billId]);
        }
        header('Location: money.php?view=bills&month=' . rawurlencode($ym), true, 303);
        exit;
    }
}

function render_budget_bar(float $actual, float $planned): void
{
    $pct = $planned > 0 ? min(140, (int) round(100 * $actual / $planned)) : ($actual > 0 ? 100 : 0);
    $over = $planned > 0 && $actual > $planned;
    echo '<div class="bar' . ($over ? ' over' : '') . '"><span style="width:' . $pct . '%"></span></div>';
}

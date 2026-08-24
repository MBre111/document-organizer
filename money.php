<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/facts_ui.php';

$pdo = db();
$flash = null;
$err = null;
$ym = money_month_param();
$view = money_view();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_token') {
        fintable_save_token((string) ($_POST['token'] ?? ''));
        header('Location: money.php?saved=1', true, 303);
        exit;
    }
    if ($action === 'forget_token') {
        fintable_save_token('');
        header('Location: money.php?forgot=1', true, 303);
        exit;
    }
    if ($action === 'sync') {
        $res = fintable_sync_all($pdo);
        if ($res['error']) {
            $err = $res['error'];
        } else {
            header(
                'Location: money.php?synced=1&a=' . (int) $res['accounts'] . '&t=' . (int) $res['transactions'],
                true,
                303
            );
            exit;
        }
    }
    if ($action === 'recurring_stop') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE recurring_bills SET status = 'stopped' WHERE id = ?")->execute([$id]);
        }
        header('Location: money.php?view=bills', true, 303);
        exit;
    }
    handle_fact_post($pdo);
    handle_money_post($pdo);
}

if (isset($_GET['saved'])) {
    $flash = isset($_GET['view']) && $_GET['view'] === 'budget'
        ? 'Budget saved for ' . money_month_label($ym) . '.'
        : 'Token saved on this PC only. It is not in git and was not sent to this chat.';
}
if (isset($_GET['forgot'])) {
    $flash = 'Token removed.';
}
if (isset($_GET['synced'])) {
    $flash = 'Synced ' . (int) ($_GET['a'] ?? 0) . ' accounts, ' . (int) ($_GET['t'] ?? 0) . ' transactions.';
}
if (isset($_GET['copied'])) {
    $flash = 'Copied last month’s planned amounts into empty buckets.';
}
if (isset($_GET['filled'])) {
    $flash = 'Filled empty buckets from monthly bills (only where planned was $0).';
}

ensure_budget_month($pdo, $ym);
$cats = budget_categories($pdo);
$roll = budget_rollups($pdo, $ym);
$tot = budget_totals($cats, $roll);
$accounts = table_exists($pdo, 'bank_accounts')
    ? $pdo->query('SELECT * FROM bank_accounts WHERE enabled = 1 ORDER BY institution, name')->fetchAll()
    : [];
$bills = table_exists($pdo, 'recurring_bills')
    ? $pdo->query("SELECT * FROM recurring_bills WHERE status = 'active' ORDER BY next_due, payee")->fetchAll()
    : [];
$lastSync = app_state_get($pdo, 'fintable_last_sync');

render_header('Money', 'money');
echo '<h1>Money</h1>';
echo '<p class="lede">Plan the month. Bank activity fills in after Fintable.</p>';
render_money_subnav($view, $ym);

if ($flash) {
    echo '<p class="flash ok">' . h($flash) . '</p>';
}
if ($err) {
    echo '<p class="flash err">' . h($err) . '</p>';
}

if ($view === 'budget') {
    money_render_budget($pdo, $ym, $cats, $roll, $tot, $bills);
} elseif ($view === 'bills') {
    money_render_bills($pdo, $ym, $cats, $bills);
} elseif ($view === 'txns') {
    money_render_txns($pdo, $ym, $cats, $bills);
} elseif ($view === 'rules') {
    money_render_rules($pdo, $cats);
} else {
    money_render_overview($pdo, $ym, $cats, $roll, $tot, $accounts, $bills, $lastSync);
}

render_footer();

/** @param list<array<string,mixed>> $cats
 *  @param array<int, array{planned:float, spent:float, received:float}> $roll
 *  @param list<array<string,mixed>> $accounts
 *  @param list<array<string,mixed>> $bills
 */
function money_render_overview(
    PDO $pdo,
    string $ym,
    array $cats,
    array $roll,
    array $tot,
    array $accounts,
    array $bills,
    ?string $lastSync
): void {
    if (!fintable_configured()) {
        echo '<section class="morning-log">';
        echo '<h2>Connect Fintable when ready</h2>';
        echo '<p class="muted">The budget works without it. When you add a read token, sync fills Activity and matches bills.</p>';
        echo '<ol class="lede">';
        echo '<li>Open <a href="https://fintable.io/dash/v2/api" rel="noreferrer">Fintable → Dashboard → API</a>.</li>';
        echo '<li>Create a <strong>Personal Access Token</strong> with <code>read</code> scope.</li>';
        echo '<li>Paste it here — stored in <code>storage/fintable.token</code>, never git, never this chat.</li>';
        echo '</ol>';
        echo '<form method="post" class="edit-doc">';
        echo '<input type="hidden" name="action" value="save_token">';
        echo '<label>Personal Access Token <input type="password" name="token" autocomplete="off" required></label>';
        echo '<button class="btn" type="submit">Save token</button>';
        echo '</form></section>';
    } else {
        echo '<form method="post" class="add-row" style="margin-bottom:1rem">';
        echo '<button class="btn" name="action" value="sync" type="submit">Sync from Fintable</button>';
        echo '<button class="btn ghost" name="action" value="forget_token" type="submit">Remove token</button>';
        echo '</form>';
        if ($lastSync) {
            echo '<p class="muted">Last sync ' . h($lastSync) . '</p>';
        }
    }

    echo '<section class="stat-grid">';
    echo '<div><span class="muted">Planned</span><strong>' . h(money_plain($tot['planned'])) . '</strong></div>';
    echo '<div><span class="muted">Spent</span><strong>' . h(money_plain($tot['spent'])) . '</strong></div>';
    echo '<div><span class="muted">Left</span><strong class="' . ($tot['left'] < 0 ? 'amt out' : '') . '">' . h(money_plain($tot['left'])) . '</strong></div>';
    echo '</section>';
    if ($tot['planned'] <= 0) {
        echo '<p><a class="btn" href="money.php?view=budget&month=' . h($ym) . '">Set ' . h(money_month_label($ym)) . ' budget</a></p>';
    }

    $cash = cash_on_hand($pdo);
    $due = unpaid_bills_soon($pdo);
    if ($cash !== null) {
        $safe = $cash - $due;
        echo '<p class="muted">Cash on hand ' . h(money_plain($cash));
        if ($due > 0) {
            echo ' − ' . h(money_plain($due)) . ' open bills ≈ <strong>' . h(money_plain($safe)) . '</strong> after bills';
        }
        echo '</p>';
    } elseif ($due > 0) {
        echo '<p class="muted">Open bills in the next month: ' . h(money_plain($due)) . ' (cash appears after Fintable sync).</p>';
    }

    $matches = [];
    if (table_exists($pdo, 'untrusted_facts') && column_exists($pdo, 'untrusted_facts', 'transaction_id')) {
        $matches = $pdo->query(
            "SELECT u.*, t.merchant, t.amount, t.posted_on
             FROM untrusted_facts u
             LEFT JOIN bank_transactions t ON t.id = u.transaction_id
             WHERE u.status = 'open' AND u.fact_key = 'bill_match'
             ORDER BY u.id DESC LIMIT 8"
        )->fetchAll();
    }
    if ($matches) {
        echo '<h2>Is this the bill?</h2><ul class="facts">';
        foreach ($matches as $fact) {
            render_fact_card($fact, 'money.php?view=overview');
        }
        echo '</ul>';
    }

    echo '<h2>Buckets</h2>';
    $shown = 0;
    echo '<ul class="budget-list">';
    foreach ($cats as $c) {
        if (($c['kind'] ?? '') !== 'expense') {
            continue;
        }
        $r = $roll[(int) $c['id']] ?? ['planned' => 0.0, 'spent' => 0.0, 'received' => 0.0];
        if ($r['planned'] <= 0 && $r['spent'] <= 0) {
            continue;
        }
        $shown++;
        echo '<li>';
        echo '<a href="money.php?view=budget&month=' . h($ym) . '">';
        echo '<strong>' . h((string) $c['name']) . '</strong>';
        echo '<span class="meta">' . h(money_plain($r['spent'])) . ' of ' . h(money_plain($r['planned'])) . '</span>';
        echo '</a>';
        render_budget_bar($r['spent'], $r['planned']);
        echo '</li>';
    }
    echo '</ul>';
    if ($shown === 0) {
        echo '<p class="muted">No planned amounts yet. Type what you want each bucket to hold this month — rent, food, locker, legal. Actuals stay $0 until Fintable syncs.</p>';
    }
    if ($tot['unsorted'] > 0) {
        echo '<p><a href="money.php?view=txns&month=' . h($ym) . '">Unsorted spending: ' . h(money_plain($tot['unsorted'])) . '</a></p>';
    }

    echo '<h2>Accounts</h2>';
    if (!$accounts) {
        echo '<p class="muted">Empty until you sync Fintable.</p>';
    } else {
        echo '<ul class="money-accts">';
        foreach ($accounts as $a) {
            echo '<li><strong>' . h((string) ($a['display_name'] ?: $a['name'])) . '</strong>';
            echo '<span class="meta">' . h((string) ($a['institution'] ?: '')) . ' · ' . h((string) $a['type']) . '</span>';
            echo '<span class="bal">' . h(money_fmt($a['balance'] !== null ? (string) $a['balance'] : null)) . '</span></li>';
        }
        echo '</ul>';
    }

    echo '<h2>Bills</h2>';
    if (!$bills) {
        echo '<p class="muted">None yet. On a water bill or lease, tap <strong>Start monthly</strong>.</p>';
    } else {
        echo '<ul class="docs">';
        foreach ($bills as $b) {
            $paid = bill_is_paid_this_cycle($pdo, $b);
            echo '<li><a href="money.php?view=bills"><strong>' . h((string) $b['payee']) . '</strong>';
            echo '<span class="meta">due ' . h((string) $b['next_due']);
            if ($b['amount'] !== null) {
                echo ' · ' . h(money_plain((float) $b['amount']));
            }
            echo $paid ? ' · paid this cycle' : ' · unpaid';
            echo '</span></a></li>';
        }
        echo '</ul>';
    }
}

/** @param list<array<string,mixed>> $cats
 *  @param array<int, array{planned:float, spent:float, received:float}> $roll
 *  @param list<array<string,mixed>> $bills
 */
function money_render_budget(PDO $pdo, string $ym, array $cats, array $roll, array $tot, array $bills): void
{
    $prev = money_month_shift($ym, -1);
    $next = money_month_shift($ym, 1);
    echo '<p class="month-nav">';
    echo '<a href="money.php?view=budget&month=' . h($prev) . '">← ' . h(money_month_label($prev)) . '</a>';
    echo ' · <strong>' . h(money_month_label($ym)) . '</strong> · ';
    echo '<a href="money.php?view=budget&month=' . h($next) . '">' . h(money_month_label($next)) . ' →</a>';
    echo '</p>';
    echo '<p class="muted">Type the cap for each bucket. Spent stays $0 until transactions land. Fill-from-bills only uses amounts you already saved on a monthly bill.</p>';
    echo '<form method="post" class="add-row" style="margin-bottom:0.75rem">';
    echo '<input type="hidden" name="month" value="' . h($ym) . '">';
    echo '<button class="btn ghost" name="action" value="copy_prev" type="submit">Copy last month</button>';
    $canFill = false;
    foreach ($bills as $b) {
        if (!empty($b['category_id']) && $b['amount'] !== null) {
            $canFill = true;
            break;
        }
    }
    if ($canFill) {
        echo '<button class="btn ghost" name="action" value="fill_bills" type="submit">Fill empty from bills</button>';
    }
    echo '</form>';

    echo '<form method="post" class="budget-form">';
    echo '<input type="hidden" name="action" value="save_plan">';
    echo '<input type="hidden" name="month" value="' . h($ym) . '">';
    echo '<h2>Expenses</h2>';
    echo '<ul class="budget-edit">';
    foreach ($cats as $c) {
        if (($c['kind'] ?? '') !== 'expense') {
            continue;
        }
        $id = (int) $c['id'];
        $r = $roll[$id] ?? ['planned' => 0.0, 'spent' => 0.0, 'received' => 0.0];
        $left = $r['planned'] - $r['spent'];
        echo '<li>';
        echo '<label>' . h((string) $c['name']);
        echo '<input type="number" name="planned[' . $id . ']" step="0.01" min="0" value="'
            . h($r['planned'] == 0.0 ? '' : number_format($r['planned'], 2, '.', '')) . '" placeholder="0.00">';
        echo '</label>';
        echo '<span class="meta">spent ' . h(money_plain($r['spent']))
            . ($r['planned'] > 0 ? ' · left ' . h(money_plain($left)) : '') . '</span>';
        render_budget_bar($r['spent'], $r['planned']);
        echo '</li>';
    }
    echo '</ul>';
    echo '<h2>Income</h2>';
    echo '<ul class="budget-edit">';
    foreach ($cats as $c) {
        if (($c['kind'] ?? '') !== 'income') {
            continue;
        }
        $id = (int) $c['id'];
        $r = $roll[$id] ?? ['planned' => 0.0, 'spent' => 0.0, 'received' => 0.0];
        echo '<li>';
        echo '<label>' . h((string) $c['name']);
        echo '<input type="number" name="planned[' . $id . ']" step="0.01" min="0" value="'
            . h($r['planned'] == 0.0 ? '' : number_format($r['planned'], 2, '.', '')) . '" placeholder="0.00">';
        echo '</label>';
        echo '<span class="meta">received ' . h(money_plain($r['received'])) . '</span>';
        echo '</li>';
    }
    echo '</ul>';
    echo '<p class="muted">This month: planned ' . h(money_plain($tot['planned']))
        . ' · spent ' . h(money_plain($tot['spent']))
        . ' · left ' . h(money_plain($tot['left'])) . '</p>';
    echo '<button class="btn" type="submit">Save plan</button>';
    echo '</form>';

    echo '<form method="post" class="edit-doc" style="margin-top:1.5rem">';
    echo '<input type="hidden" name="action" value="add_category">';
    echo '<input type="hidden" name="month" value="' . h($ym) . '">';
    echo '<h2>Add a bucket</h2>';
    echo '<label>Name <input name="name" placeholder="Dog food, storage locker…" required></label>';
    echo '<label>Type <select name="kind"><option value="expense">Expense</option><option value="income">Income</option></select></label>';
    echo '<button class="btn ghost" type="submit">Add</button>';
    echo '</form>';
}

/** @param list<array<string,mixed>> $cats
 *  @param list<array<string,mixed>> $bills
 */
function money_render_bills(PDO $pdo, string $ym, array $cats, array $bills): void
{
    echo '<p class="muted">These are the repeating charges you marked on documents. After Fintable syncs, matching charges get a Yes/Skip bubble instead of being guessed.</p>';
    $matches = [];
    if (table_exists($pdo, 'untrusted_facts') && column_exists($pdo, 'untrusted_facts', 'transaction_id')) {
        $matches = $pdo->query(
            "SELECT * FROM untrusted_facts WHERE status = 'open' AND fact_key = 'bill_match' ORDER BY id DESC"
        )->fetchAll();
    }
    if ($matches) {
        echo '<h2>Needs a tap</h2><ul class="facts">';
        foreach ($matches as $fact) {
            render_fact_card($fact, 'money.php?view=bills');
        }
        echo '</ul>';
    }
    if (!$bills) {
        echo '<p class="muted">No monthly bills yet. Open a catalogued water bill or lease and tap <strong>Start monthly</strong>.</p>';
        return;
    }
    echo '<ul class="bill-cards">';
    foreach ($bills as $b) {
        $paid = bill_is_paid_this_cycle($pdo, $b);
        $txn = $paid ? bill_matched_txn($pdo, $b) : null;
        echo '<li class="' . ($paid ? 'paid' : 'unpaid') . '">';
        echo '<strong>' . h((string) $b['payee']) . '</strong>';
        echo '<span class="meta">due ' . h((string) $b['next_due']);
        if ($b['amount'] !== null) {
            echo ' · ' . h(money_plain((float) $b['amount']));
        }
        echo '</span>';
        if ($paid && $txn) {
            echo '<p class="muted">Matched ' . h((string) $txn['posted_on']) . ' · '
                . h((string) ($txn['merchant'] ?: $txn['description'])) . '</p>';
        } else {
            echo '<p class="muted">Waiting for a matching charge'
                . (fintable_configured() ? '.' : ' (connect Fintable).') . '</p>';
        }
        echo '<form method="post" class="link-add">';
        echo '<input type="hidden" name="action" value="link_bill_cat">';
        echo '<input type="hidden" name="bill_id" value="' . (int) $b['id'] . '">';
        echo '<input type="hidden" name="month" value="' . h($ym) . '">';
        echo '<label>Budget bucket <select name="category_id" onchange="this.form.submit()">';
        echo '<option value="0">—</option>';
        foreach ($cats as $c) {
            if (($c['kind'] ?? '') === 'transfer') {
                continue;
            }
            $sel = (int) ($b['category_id'] ?? 0) === (int) $c['id'] ? ' selected' : '';
            echo '<option value="' . (int) $c['id'] . '"' . $sel . '>' . h((string) $c['name']) . '</option>';
        }
        echo '</select></label></form>';
        if (!empty($b['document_id'])) {
            echo '<p><a href="document.php?id=' . (int) $b['document_id'] . '">Source document</a></p>';
        }
        echo '<form method="post" class="inline-form">';
        echo '<input type="hidden" name="id" value="' . (int) $b['id'] . '">';
        echo '<button class="btn small ghost" name="action" value="recurring_stop" type="submit">Stop</button>';
        echo '</form></li>';
    }
    echo '</ul>';
}

/** @param list<array<string,mixed>> $cats
 *  @param list<array<string,mixed>> $bills
 */
function money_render_txns(PDO $pdo, string $ym, array $cats, array $bills): void
{
    $prev = money_month_shift($ym, -1);
    $next = money_month_shift($ym, 1);
    echo '<p class="month-nav">';
    echo '<a href="money.php?view=txns&month=' . h($prev) . '">← ' . h(money_month_label($prev)) . '</a>';
    echo ' · <strong>' . h(money_month_label($ym)) . '</strong> · ';
    echo '<a href="money.php?view=txns&month=' . h($next) . '">' . h(money_month_label($next)) . ' →</a>';
    echo '</p>';
    if (!table_exists($pdo, 'bank_transactions')) {
        echo '<p class="muted">No activity table yet.</p>';
        return;
    }
    $onlyOpen = (string) ($_GET['filter'] ?? '') === 'open';
    echo '<p class="muted"><a href="money.php?view=txns&month=' . h($ym) . '">All</a> · ';
    echo '<a href="money.php?view=txns&month=' . h($ym) . '&filter=open">Unsorted only</a></p>';
    $start = $ym . '-01';
    $end = money_month_shift($ym, 1) . '-01';
    $sql = "SELECT t.*, a.display_name, a.name AS account_name
            FROM bank_transactions t
            JOIN bank_accounts a ON a.id = t.account_id
            WHERE t.posted_on >= ? AND t.posted_on < ?";
    $params = [$start, $end];
    if ($onlyOpen) {
        $sql .= " AND t.budget_category_id IS NULL AND COALESCE(t.is_transfer,0)=0 AND COALESCE(t.review_status,'open') <> 'ignored'";
    }
    $sql .= ' ORDER BY t.posted_on DESC, t.id DESC LIMIT 80';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        echo '<p class="muted">No charges this month yet. Connect Fintable and sync — this list is the inbox for money.</p>';
        return;
    }
    echo '<ul class="tx-list">';
    foreach ($rows as $t) {
        $cid = (int) ($t['budget_category_id'] ?? 0);
        echo '<li>';
        echo '<span class="when">' . h((string) $t['posted_on']) . '</span>';
        echo '<span>' . h((string) ($t['merchant'] ?: $t['description'] ?: 'Transaction')) . '</span>';
        echo '<span class="meta">' . h((string) ($t['display_name'] ?: $t['account_name']));
        if (!empty($t['category'])) {
            echo ' · ' . h((string) $t['category']);
        }
        if (!empty($t['is_transfer'])) {
            echo ' · transfer';
        } elseif (($t['review_status'] ?? '') === 'ignored') {
            echo ' · ignored';
        }
        echo '</span>';
        echo '<span class="amt ' . (((float) $t['amount']) < 0 ? 'out' : 'in') . '">'
            . h(money_fmt((string) $t['amount'])) . '</span>';
        echo '<form method="post" class="txn-assign">';
        echo '<input type="hidden" name="action" value="assign_txn">';
        echo '<input type="hidden" name="txn_id" value="' . (int) $t['id'] . '">';
        echo '<input type="hidden" name="month" value="' . h($ym) . '">';
        echo '<select name="category_id">';
        echo '<option value="0">Bucket…</option>';
        foreach ($cats as $c) {
            $sel = $cid === (int) $c['id'] ? ' selected' : '';
            echo '<option value="' . (int) $c['id'] . '"' . $sel . '>' . h((string) $c['name']) . '</option>';
        }
        echo '</select>';
        if ($bills) {
            echo '<select name="bill_id">';
            echo '<option value="0">Bill…</option>';
            foreach ($bills as $b) {
                $sel = (int) ($t['recurring_bill_id'] ?? 0) === (int) $b['id'] ? ' selected' : '';
                echo '<option value="' . (int) $b['id'] . '"' . $sel . '>' . h((string) $b['payee']) . '</option>';
            }
            echo '</select>';
        }
        echo '<label class="switch"><input type="checkbox" name="always" value="1"> Always</label>';
        echo '<button class="btn small" type="submit">Save</button>';
        echo '<button class="btn small ghost" name="action" value="txn_transfer" type="submit">Transfer</button>';
        echo '<button class="btn small ghost" name="action" value="txn_ignore" type="submit">Ignore</button>';
        echo '</form></li>';
    }
    echo '</ul>';
}

/** @param list<array<string,mixed>> $cats */
function money_render_rules(PDO $pdo, array $cats): void
{
    echo '<p class="muted">Rules run on every Fintable sync. Add them now so the first download lands in the right bucket. Tick <strong>Always</strong> on Activity to create one from a real charge.</p>';
    $rules = table_exists($pdo, 'money_rules')
        ? $pdo->query(
            'SELECT r.*, c.name AS cat_name FROM money_rules r
             LEFT JOIN budget_categories c ON c.id = r.category_id
             ORDER BY r.id DESC'
        )->fetchAll()
        : [];
    echo '<form method="post" class="edit-doc">';
    echo '<input type="hidden" name="action" value="add_rule">';
    echo '<h2>New rule</h2>';
    echo '<label>When <select name="match_kind">';
    echo '<option value="merchant">merchant contains</option>';
    echo '<option value="description">description contains</option>';
    echo '<option value="fintable_category">Fintable category contains</option>';
    echo '<option value="payee">any text contains</option>';
    echo '</select></label>';
    echo '<label>Text <input name="match_value" placeholder="PASCO, WINDSOR, U-HAUL…" required></label>';
    echo '<label>Put in <select name="category_id">';
    foreach ($cats as $c) {
        echo '<option value="' . (int) $c['id'] . '">' . h((string) $c['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<button class="btn" type="submit">Add rule</button>';
    echo '</form>';
    if (!$rules) {
        echo '<p class="muted">No rules yet. Example: merchant contains PASCO → Utilities.</p>';
        return;
    }
    echo '<ul class="docs">';
    foreach ($rules as $r) {
        echo '<li><strong>' . h((string) $r['match_kind']) . '</strong> “' . h((string) $r['match_value']) . '”';
        echo '<span class="meta">→ ' . h((string) ($r['cat_name'] ?: ($r['is_transfer'] ? 'transfer' : 'unassigned'))) . '</span>';
        echo '<form method="post" class="inline-form">';
        echo '<input type="hidden" name="id" value="' . (int) $r['id'] . '">';
        echo '<button class="btn small ghost" name="action" value="del_rule" type="submit">Remove</button>';
        echo '</form></li>';
    }
    echo '</ul>';
}

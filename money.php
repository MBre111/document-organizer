<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$pdo = db();
$flash = null;
$err = null;

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
        header('Location: money.php', true, 303);
        exit;
    }
}

if (isset($_GET['saved'])) {
    $flash = 'Token saved on this PC only. It is not in git and was not sent to this chat.';
}
if (isset($_GET['forgot'])) {
    $flash = 'Token removed.';
}
if (isset($_GET['synced'])) {
    $flash = 'Synced ' . (int) ($_GET['a'] ?? 0) . ' accounts, ' . (int) ($_GET['t'] ?? 0) . ' transactions.';
}

$accounts = table_exists($pdo, 'bank_accounts')
    ? $pdo->query('SELECT * FROM bank_accounts WHERE enabled = 1 ORDER BY institution, name')->fetchAll()
    : [];
$txs = table_exists($pdo, 'bank_transactions')
    ? $pdo->query(
        "SELECT t.*, a.display_name, a.name AS account_name, a.institution
         FROM bank_transactions t
         JOIN bank_accounts a ON a.id = t.account_id
         ORDER BY t.posted_on DESC, t.id DESC
         LIMIT 40"
    )->fetchAll()
    : [];
$bills = table_exists($pdo, 'recurring_bills')
    ? $pdo->query("SELECT * FROM recurring_bills WHERE status = 'active' ORDER BY next_due, payee")->fetchAll()
    : [];
$lastSync = app_state_get($pdo, 'fintable_last_sync');

render_header('Money', 'money');
?>
<h1>Money</h1>
<p class="lede">Bank balances and transactions via <a href="https://fintable.io" rel="noreferrer">Fintable</a>. Recurring bills live here too. First-party only — this is your account, on this PC.</p>

<?php if ($flash): ?>
    <p class="flash ok"><?= h($flash) ?></p>
<?php endif; ?>
<?php if ($err): ?>
    <p class="flash err"><?= h($err) ?></p>
<?php endif; ?>

<?php if (!fintable_configured()): ?>
    <section class="morning-log">
        <h2>Connect Fintable</h2>
        <ol class="lede">
            <li>Open <a href="https://fintable.io/dash/v2/api" rel="noreferrer">Fintable → Dashboard → API</a> (sign in there, not here).</li>
            <li>Create a <strong>Personal Access Token</strong> with <code>read</code> scope. Copy it once.</li>
            <li>Paste it below. It is stored in <code>storage/fintable.token</code> on this machine, never in git.</li>
        </ol>
        <p class="muted">Do not paste your Fintable password, or your bank password, into this app or into Grok chat.</p>
        <form method="post" class="edit-doc">
            <input type="hidden" name="action" value="save_token">
            <label>Personal Access Token <input type="password" name="token" autocomplete="off" required></label>
            <button class="btn" type="submit">Save token</button>
        </form>
    </section>
<?php else: ?>
    <form method="post" class="add-row" style="margin-bottom:1rem">
        <button class="btn" name="action" value="sync" type="submit">Sync from Fintable</button>
        <button class="btn ghost" name="action" value="forget_token" type="submit">Remove token</button>
    </form>
    <?php if ($lastSync): ?>
        <p class="muted">Last sync <?= h($lastSync) ?></p>
    <?php endif; ?>
<?php endif; ?>

<h2>Accounts</h2>
<?php if (!$accounts): ?>
    <p class="muted"><?= fintable_configured() ? 'No accounts yet. Tap Sync after banks are connected in Fintable.' : 'Connect a token, then sync.' ?></p>
<?php else: ?>
    <ul class="money-accts">
        <?php foreach ($accounts as $a): ?>
            <li>
                <strong><?= h((string) ($a['display_name'] ?: $a['name'])) ?></strong>
                <span class="meta"><?= h((string) ($a['institution'] ?: '')) ?> · <?= h((string) $a['type']) ?></span>
                <span class="bal"><?= h(money_fmt($a['balance'] !== null ? (string) $a['balance'] : null, (string) $a['currency'])) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h2>Monthly bills</h2>
<?php if (!$bills): ?>
    <p class="muted">None yet. On a utility bill or lease, use <strong>Start monthly</strong>.</p>
<?php else: ?>
    <ul class="docs">
        <?php foreach ($bills as $b): ?>
            <li>
                <strong><?= h((string) $b['payee']) ?></strong>
                <span class="meta">
                    next <?= h((string) $b['next_due']) ?>
                    <?= $b['amount'] !== null ? ' · $' . h(number_format((float) $b['amount'], 2)) : '' ?>
                    · day <?= h((string) $b['day_of_month']) ?>
                    <?php if ($b['document_id']): ?>
                        · <a href="document.php?id=<?= (int) $b['document_id'] ?>">doc</a>
                    <?php endif; ?>
                </span>
                <form method="post" class="inline-form">
                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                    <button class="btn small ghost" name="action" value="recurring_stop" type="submit">Stop</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h2>Recent transactions</h2>
<?php if (!$txs): ?>
    <p class="muted">Nothing pulled yet.</p>
<?php else: ?>
    <ul class="tx-list">
        <?php foreach ($txs as $t): ?>
            <li>
                <span class="when"><?= h((string) $t['posted_on']) ?></span>
                <span><?= h((string) ($t['merchant'] ?: $t['description'] ?: 'Transaction')) ?></span>
                <span class="meta"><?= h((string) ($t['display_name'] ?: $t['account_name'])) ?><?= $t['category'] ? ' · ' . h((string) $t['category']) : '' ?></span>
                <span class="amt <?= ((float) $t['amount']) < 0 ? 'out' : 'in' ?>"><?= h(money_fmt((string) $t['amount'], (string) $t['currency'])) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php
render_footer();

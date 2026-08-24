<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$pdo = db();
$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $id = save_medication($pdo, $_POST);
        header('Location: meds.php?added=' . ($id > 0 ? '1' : '0'), true, 303);
        exit;
    }
    if ($action === 'status') {
        set_medication_status($pdo, (int) ($_POST['id'] ?? 0), (string) ($_POST['status'] ?? ''));
        header('Location: meds.php', true, 303);
        exit;
    }
    if ($action === 'med_log') {
        med_set_log(
            $pdo,
            (int) ($_POST['med_id'] ?? 0),
            date('Y-m-d'),
            (string) ($_POST['slot'] ?? 'morning'),
            (string) ($_POST['status'] ?? 'taken')
        );
        header('Location: meds.php', true, 303);
        exit;
    }
}

if (isset($_GET['added'])) {
    $flash = $_GET['added'] === '1' ? 'Saved. It will show on Today.' : 'Need a name — I will not guess one.';
}

$active = table_exists($pdo, 'medications')
    ? $pdo->query("SELECT * FROM medications WHERE status = 'active' ORDER BY kind, sort_order, name")->fetchAll()
    : [];
$paused = table_exists($pdo, 'medications')
    ? $pdo->query("SELECT * FROM medications WHERE status <> 'active' ORDER BY name")->fetchAll()
    : [];
$logs = med_log_map($pdo, date('Y-m-d'));

render_header('Meds', 'today');
?>
<p class="back"><a href="index.php">← Today</a></p>
<h1>Meds &amp; supplements</h1>
<p class="lede">You type the names. The old Google sheet only had time windows (5a–9a, 7a–9a) — nothing is invented here.</p>
<?php if ($flash): ?>
    <p class="flash <?= str_starts_with($flash, 'Saved') ? 'ok' : 'err' ?>"><?= h($flash) ?></p>
<?php endif; ?>

<section class="coming-up">
    <h2>Add one</h2>
    <form method="post" class="edit-doc">
        <input type="hidden" name="action" value="add">
        <label>Name <input name="name" required placeholder="What the bottle says" autocomplete="off"></label>
        <label>Kind
            <select name="kind">
                <option value="supplement">Supplement</option>
                <option value="medication">Medication</option>
            </select>
        </label>
        <label>Dose <input name="dose" placeholder="e.g. 500 mg or 2 capsules"></label>
        <label>When
            <select name="timing">
                <?php foreach (med_timings() as $k => $lab): ?>
                    <option value="<?= h($k) ?>" <?= $k === 'morning' ? 'selected' : '' ?>><?= h($lab) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Time window (optional)
            <input name="time_window" list="windows" placeholder="5a-9a">
            <datalist id="windows">
                <option value="5a-9a">
                <option value="7a-9a">
            </datalist>
        </label>
        <label>Notes <input name="notes" placeholder="With food, prescriber…"></label>
        <button class="btn" type="submit">Add to list</button>
    </form>
</section>

<h2>Today’s list</h2>
<?php if (!$active): ?>
    <p class="muted">Empty on purpose until you add something. The generic Meds habit on Today still works as a single checkbox.</p>
<?php else: ?>
    <ul class="docs med-list">
        <?php foreach ($active as $m): ?>
            <?php
            $slots = med_slots_for_timing((string) $m['timing']);
            $bits = [];
            foreach ($slots as $slot) {
                $st = $logs[(int) $m['id'] . ':' . $slot] ?? '';
                $bits[] = $slot . ($st ? ':' . $st : '');
            }
            ?>
            <li>
                <strong><?= h((string) $m['name']) ?></strong>
                <span class="meta">
                    <?= h(med_kinds()[(string) $m['kind']] ?? $m['kind']) ?>
                    <?= !empty($m['dose']) ? ' · ' . h((string) $m['dose']) : '' ?>
                    · <?= h(med_timings()[(string) $m['timing']] ?? $m['timing']) ?>
                    <?= !empty($m['time_window']) ? ' · ' . h((string) $m['time_window']) : '' ?>
                    · <?= h(implode(', ', $bits)) ?>
                </span>
                <?php if (!empty($m['notes'])): ?>
                    <span class="sum"><?= h((string) $m['notes']) ?></span>
                <?php endif; ?>
                <form method="post" class="inline-form">
                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                    <input type="hidden" name="status" value="paused">
                    <button class="btn small ghost" name="action" value="status" type="submit">Pause</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($paused): ?>
    <h2>Paused / stopped</h2>
    <ul class="docs">
        <?php foreach ($paused as $m): ?>
            <li>
                <strong><?= h((string) $m['name']) ?></strong>
                <span class="meta"><?= h((string) $m['status']) ?></span>
                <form method="post" class="inline-form">
                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                    <input type="hidden" name="status" value="active">
                    <button class="btn small ghost" name="action" value="status" type="submit">Resume</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php
render_footer();

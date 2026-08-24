<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$pdo = db();
$q = trim((string) ($_GET['q'] ?? ''));
$hits = search_all($pdo, $q, 10);
$n = search_total($hits);

render_header($q !== '' ? ('Search · ' . $q) : 'Search', org_nav());
?>
<h1>Search</h1>
<form class="search" method="get" action="search.php">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="6082, Olivia, locker, Pasco…" autofocus>
    <button type="submit">Search</button>
</form>
<?php if ($q === ''): ?>
    <p class="lede">Documents, wiki, journal, and tasks in one place.</p>
<?php elseif (mb_strlen($q) < 2): ?>
    <p class="muted">Type at least two characters.</p>
<?php elseif ($n === 0): ?>
    <p class="muted">Nothing matched “<?= h($q) ?>”.</p>
<?php else: ?>
    <p class="muted"><?= $n ?> result<?= $n === 1 ? '' : 's' ?> for “<?= h($q) ?>”.</p>
<?php endif; ?>

<?php if ($hits['entities']): ?>
    <h2>Wiki</h2>
    <ul class="docs">
        <?php foreach ($hits['entities'] as $row): ?>
            <li>
                <a href="entity.php?id=<?= (int) $row['id'] ?>">
                    <strong><?= h((string) $row['name']) ?></strong>
                    <span class="meta"><?= h((string) $row['kind']) ?></span>
                    <?php if (!empty($row['preview'])): ?>
                        <span class="sum"><?= h((string) $row['preview']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($hits['deadlines']): ?>
    <h2>Tasks</h2>
    <ul class="docs">
        <?php foreach ($hits['deadlines'] as $row): ?>
            <li>
                <a href="deadline.php?id=<?= (int) $row['id'] ?>">
                    <strong><?= h((string) $row['title']) ?></strong>
                    <span class="meta"><?= h((string) $row['due_on']) ?> · <?= h(kind_label((string) $row['kind'])) ?> · <?= h((string) $row['status']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($hits['journals']): ?>
    <h2>Journal</h2>
    <ul class="docs">
        <?php foreach ($hits['journals'] as $row): ?>
            <li>
                <a href="journal.php?id=<?= (int) $row['id'] ?>">
                    <strong><?= h((string) ($row['title'] ?: 'Journal')) ?></strong>
                    <span class="meta"><?= h((string) $row['entry_date']) ?></span>
                    <span class="sum"><?= h((string) $row['preview']) ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($hits['documents']): ?>
    <h2>Documents</h2>
    <ul class="docs">
        <?php foreach ($hits['documents'] as $row): ?>
            <li>
                <a href="document.php?id=<?= (int) $row['id'] ?>">
                    <strong><?= h((string) ($row['title'] ?: 'Untitled')) ?></strong>
                    <span class="meta">
                        <?= h((string) ($row['doc_type'] ?? '')) ?>
                        <?= !empty($row['source_org']) ? ' · ' . h((string) $row['source_org']) : '' ?>
                        <?= !empty($row['doc_date']) ? ' · ' . h((string) $row['doc_date']) : '' ?>
                        · <?= h((string) $row['review_status']) ?>
                    </span>
                    <?php if (!empty($row['summary'])): ?>
                        <span class="sum"><?= h((string) $row['summary']) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php
render_footer();

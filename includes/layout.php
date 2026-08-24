<?php

declare(strict_types=1);

function org_nav(?string $set = null): string
{
    static $nav = 'today';
    if ($set !== null) {
        $nav = $set;
    }
    return $nav;
}

function render_header(string $title, string $nav = 'today'): void
{
    org_nav($nav);
    $factN = 0;
    $inboxN = 0;
    try {
        $pdo = db();
        $factN = (int) $pdo->query("SELECT COUNT(*) FROM untrusted_facts WHERE status = 'open'")->fetchColumn();
        $inboxN = (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE review_status = 'inbox'")->fetchColumn();
    } catch (Throwable $e) {
        // nav still renders
    }
    $on = static function (string $key) use ($nav): string {
        return $nav === $key ? 'on' : '';
    };
    $filesOn = in_array($nav, ['inbox', 'library'], true);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#12150f">
    <title><?= h($title) ?> · Organizer</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="top">
    <a class="brand" href="index.php">Organizer</a>
    <nav class="top-nav">
        <a class="<?= $on('today') ?>" href="index.php">Today</a>
        <a class="<?= $on('inbox') ?>" href="index.php?view=inbox">Inbox<?php if ($inboxN): ?> <span class="nav-count"><?= $inboxN ?></span><?php endif; ?></a>
        <a class="<?= $on('library') ?>" href="index.php?view=library">Library</a>
        <a class="<?= $on('journal') ?>" href="journal.php">Journal</a>
        <a class="<?= $on('money') ?>" href="money.php">Money</a>
        <a class="<?= $on('facts') ?>" href="facts.php">Confirm<?php if ($factN): ?> <span class="nav-count"><?= $factN ?></span><?php endif; ?></a>
    </nav>
    <form class="top-search" method="get" action="search.php" role="search">
        <input type="search" name="q" value="<?= h((string) ($_GET['q'] ?? '')) ?>" placeholder="Search…" aria-label="Search">
    </form>
    <a class="btn small add-scan <?= $on('upload') ?>" href="upload.php">+ Scan</a>
</header>
<main>
    <?php
}

function render_footer(): void
{
    $nav = org_nav();
    $factN = 0;
    $inboxN = 0;
    try {
        $pdo = db();
        $factN = (int) $pdo->query("SELECT COUNT(*) FROM untrusted_facts WHERE status = 'open'")->fetchColumn();
        $inboxN = (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE review_status = 'inbox'")->fetchColumn();
    } catch (Throwable $e) {
    }
    $filesOn = in_array($nav, ['inbox', 'library', 'upload'], true);
    $filesHref = $inboxN > 0 ? 'index.php?view=inbox' : 'index.php?view=library';
    ?>
</main>
<nav class="dock" aria-label="Main">
    <a class="<?= $nav === 'today' ? 'on' : '' ?>" href="index.php"><span>Today</span></a>
    <a class="<?= $filesOn ? 'on' : '' ?>" href="<?= h($filesHref) ?>"><span>Files</span><?php if ($inboxN): ?><em><?= $inboxN ?></em><?php endif; ?></a>
    <a class="<?= $nav === 'journal' ? 'on' : '' ?>" href="journal.php"><span>Journal</span></a>
    <a class="<?= $nav === 'money' ? 'on' : '' ?>" href="money.php"><span>Money</span></a>
    <a class="<?= $nav === 'facts' ? 'on' : '' ?>" href="facts.php"><span>Confirm</span><?php if ($factN): ?><em><?= $factN ?></em><?php endif; ?></a>
</nav>
</body>
</html>
    <?php
}

<?php

declare(strict_types=1);

function render_header(string $title, string $nav = 'today'): void
{
    $factN = 0;
    $inboxN = 0;
    try {
        $pdo = db();
        $factN = (int) $pdo->query("SELECT COUNT(*) FROM untrusted_facts WHERE status = 'open'")->fetchColumn();
        $inboxN = (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE review_status = 'inbox'")->fetchColumn();
    } catch (Throwable $e) {
        // nav still renders
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= h($title) ?> · Organizer</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="top">
    <a class="brand" href="index.php">Organizer</a>
    <nav>
        <a class="<?= $nav === 'today' ? 'on' : '' ?>" href="index.php">Today</a>
        <a class="<?= $nav === 'inbox' ? 'on' : '' ?>" href="index.php?view=inbox">Inbox<?php if ($inboxN): ?> <span class="nav-count"><?= $inboxN ?></span><?php endif; ?></a>
        <a class="<?= $nav === 'library' ? 'on' : '' ?>" href="index.php?view=library">Library</a>
        <a class="<?= $nav === 'facts' ? 'on' : '' ?>" href="facts.php">Untrusted<?php if ($factN): ?> <span class="nav-count"><?= $factN ?></span><?php endif; ?></a>
        <a class="<?= $nav === 'upload' ? 'on' : '' ?>" href="upload.php">Upload</a>
    </nav>
</header>
<main>
    <?php
}

function render_footer(): void
{
    ?>
</main>
</body>
</html>
    <?php
}

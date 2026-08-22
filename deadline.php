<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$pdo = db();
handle_deadline_post($pdo);
header('Location: index.php', true, 303);

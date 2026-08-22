<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $root = new PDO(
        'mysql:host=' . $config['db_host'] . ';charset=utf8mb4',
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $root->exec($sql);

    foreach (['inbox', 'library'] as $dir) {
        $path = $config['storage'] . DIRECTORY_SEPARATOR . $dir;
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create ' . $path);
        }
        file_put_contents($path . DIRECTORY_SEPARATOR . 'index.php', "<?php\nhttp_response_code(403);\n");
    }

    echo "Organizer database and storage folders are ready.\n";
    echo "Open http://localhost/organizer/\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Install failed: " . $e->getMessage() . "\n";
    echo "Start Wamp (green icon), then reload this page.\n";
}

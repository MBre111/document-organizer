<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM cases WHERE id = ?');
$stmt->execute([$id]);
$case = $stmt->fetch();
if (!$case) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$docs = $pdo->prepare(
    'SELECT * FROM documents WHERE case_id = ? AND review_status NOT IN (\'out_of_scope\')
     ORDER BY COALESCE(doc_date, created_at) ASC, id ASC'
);
$docs->execute([$id]);
$docs = $docs->fetchAll();

$parties = $pdo->prepare(
    "SELECT DISTINCT e.name, de.role
     FROM document_entities de
     JOIN entities e ON e.id = de.entity_id
     JOIN documents d ON d.id = de.document_id
     WHERE d.case_id = ?"
);
$parties->execute([$id]);
$parties = $parties->fetchAll();

$lines = [];
$lines[] = $case['case_number'];
$lines[] = $case['title'] ?? '';
$lines[] = $case['court'] ?? '';
$lines[] = '';
$lines[] = 'Parties:';
foreach ($parties as $p) {
    $lines[] = '  ' . $p['role'] . ': ' . $p['name'];
}
$lines[] = '';
$lines[] = 'Timeline:';
foreach ($docs as $d) {
    $lines[] = sprintf(
        '  %s  [%s]  %s  (%s)',
        $d['doc_date'] ?: 'undated',
        $d['doc_type'] ?: 'doc',
        $d['title'] ?: 'Untitled',
        $d['review_status']
    );
    if (!empty($d['summary'])) {
        $lines[] = '    ' . preg_replace('/\s+/', ' ', (string) $d['summary']);
    }
}

$tmp = tempnam(sys_get_temp_dir(), 'pkt');
$zipPath = $tmp . '.zip';
@unlink($tmp);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    echo 'Could not create zip';
    exit;
}
$zip->addFromString('00-timeline.txt', implode("\n", $lines) . "\n");
$n = 1;
foreach ($docs as $d) {
    $files = $pdo->prepare('SELECT * FROM files WHERE document_id = ? ORDER BY page_no, id');
    $files->execute([(int) $d['id']]);
    foreach ($files->fetchAll() as $file) {
        $full = storage_path((string) $file['stored_path']);
        if (!is_file($full)) {
            continue;
        }
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $file['original_filename']) ?: 'file';
        $zip->addFile($full, sprintf('%02d-d%s-%s', $n, $d['id'], $safe));
        $n++;
    }
}
$zip->close();

$download = organizer_slug((string) $case['case_number']) . '-packet.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $download . '"');
header('Content-Length: ' . (string) filesize($zipPath));
readfile($zipPath);
@unlink($zipPath);
exit;

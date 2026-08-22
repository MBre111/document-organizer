<?php

declare(strict_types=1);

function drive_root(): string
{
    global $config;
    $root = (string) ($config['drive_root'] ?? 'G:\\My Drive');
    return rtrim($root, '\\/');
}

function organizer_slug(string $title): string
{
    $s = strtolower($title);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? 'doc';
    $s = trim($s, '-');
    return $s !== '' ? substr($s, 0, 80) : 'doc';
}

function type_folder(?string $type): string
{
    $map = [
        'housing_lease' => 'housing',
        'housing_notice' => 'housing',
        'court_filing' => 'court',
        'court_summons' => 'court',
        'utility_bill' => 'utilities',
        'bank_letter' => 'financial',
        'bank_statement' => 'financial',
        'tax_notice' => 'financial',
        'tax_return' => 'financial',
        'medical_record' => 'medical',
        'medical_bill' => 'medical',
        'appointment' => 'medical',
        'serial_plate' => 'inventory',
        'photo_evidence' => 'housing',
        'id_document' => 'identity',
        'insurance' => 'financial',
        'correspondence' => 'other',
        'receipt' => 'financial',
        'other' => 'other',
    ];
    return $map[$type ?? ''] ?? 'other';
}

function parse_google_stub(string $path): ?array
{
    if (!is_file($path) || filesize($path) > 4096) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return null;
    }
    $id = $j['doc_id'] ?? $j['resource_id'] ?? null;
    $url = (string) ($j['url'] ?? '');
    if (!$id && preg_match('#/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        $id = $m[1];
    }
    if (!$id && preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $url, $m)) {
        $id = $m[1];
    }
    if (!is_string($id) || strlen($id) < 10) {
        return null;
    }
    $kind = 'document';
    if (str_contains($url, 'spreadsheets') || str_ends_with(strtolower($path), '.gsheet')) {
        $kind = 'sheet';
    }
    return ['id' => $id, 'url' => $url, 'kind' => $kind];
}

function http_fetch(string $url): ?string
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 12,
            'follow_location' => 1,
            'header' => "User-Agent: Organizer/1.0\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if (!is_string($data) || strlen($data) < 80) {
        return null;
    }
    $trim = ltrim($data);
    if (str_starts_with($trim, '<') || str_contains(substr($trim, 0, 200), 'Sign in')) {
        return null;
    }
    return $data;
}

function find_drive_sibling(array $file, string $wantExt): ?string
{
    $drivePath = (string) ($file['drive_path'] ?? '');
    $orig = (string) ($file['original_filename'] ?? '');
    $candidates = [];
    if ($drivePath !== '') {
        $base = preg_replace('/\.(gdoc|gsheet|gslides)$/i', '', $drivePath);
        $candidates[] = drive_root() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $base . '.' . $wantExt);
        $dir = dirname(drive_root() . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $drivePath));
        $stem = preg_replace('/\.(gdoc|gsheet|gslides)$/i', '', basename($orig));
        if ($stem) {
            $candidates[] = $dir . DIRECTORY_SEPARATOR . $stem . '.' . $wantExt;
        }
    }
    foreach ($candidates as $c) {
        if (is_file($c) && filesize($c) > 200) {
            return $c;
        }
    }
    return null;
}

function attach_exported_file(PDO $pdo, int $docId, string $srcPath, string $origName, string $mime, string $source): void
{
    $inbox = storage_path('inbox');
    if (!is_dir($inbox)) {
        mkdir($inbox, 0775, true);
    }
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION) ?: 'bin');
    $storedName = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    $relative = 'inbox/' . $storedName;
    if (!@copy($srcPath, storage_path($relative))) {
        return;
    }
    $hash = hash_file('sha256', storage_path($relative)) ?: null;
    if ($hash) {
        $dup = $pdo->prepare('SELECT id FROM files WHERE sha256 = ? LIMIT 1');
        $dup->execute([$hash]);
        if ($dup->fetch()) {
            @unlink(storage_path($relative));
            return;
        }
    }
    $max = $pdo->prepare('SELECT COALESCE(MAX(page_no),0) FROM files WHERE document_id = ?');
    $max->execute([$docId]);
    $page = (int) $max->fetchColumn() + 1;
    $pdo->prepare(
        'INSERT INTO files (document_id, original_filename, stored_path, mime, byte_size, sha256, page_no, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $docId,
        $origName,
        $relative,
        $mime,
        filesize(storage_path($relative)) ?: 0,
        $hash,
        $page,
        $source,
    ]);
}

function materialize_google_file(PDO $pdo, array $file): bool
{
    $full = storage_path((string) $file['stored_path']);
    $stub = parse_google_stub($full);
    $id = $stub['id'] ?? $file['drive_file_id'] ?? null;
    if ($stub && empty($file['drive_file_id'])) {
        $pdo->prepare('UPDATE files SET drive_file_id = ? WHERE id = ?')->execute([$stub['id'], (int) $file['id']]);
        $id = $stub['id'];
    }
    $orig = strtolower((string) ($file['original_filename'] ?? $file['stored_path'] ?? ''));
    $isDoc = str_ends_with($orig, '.gdoc') || (($stub['kind'] ?? '') === 'document');
    $isSheet = str_ends_with($orig, '.gsheet') || (($stub['kind'] ?? '') === 'sheet');
    if (!$isDoc && !$isSheet) {
        return false;
    }
    $wantExt = $isSheet ? 'csv' : 'pdf';
    $mime = $isSheet ? 'text/csv' : 'application/pdf';
    $sibling = find_drive_sibling($file, $isSheet ? 'csv' : 'pdf');
    if (!$sibling && $isSheet) {
        $sibling = find_drive_sibling($file, 'xlsx');
        if ($sibling) {
            $wantExt = 'xlsx';
            $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        }
    }
    $tmp = null;
    if ($sibling) {
        $tmp = $sibling;
    } elseif (is_string($id) && $id !== '') {
        $url = $isSheet
            ? 'https://docs.google.com/spreadsheets/d/' . rawurlencode($id) . '/export?format=csv'
            : 'https://docs.google.com/document/d/' . rawurlencode($id) . '/export?format=pdf';
        $data = http_fetch($url);
        if ($data !== null && ($isSheet || str_starts_with($data, '%PDF'))) {
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gexp-' . bin2hex(random_bytes(3)) . '.' . $wantExt;
            file_put_contents($tmp, $data);
        }
    }
    if (!$tmp || !is_file($tmp)) {
        return false;
    }
    $name = preg_replace('/\.(gdoc|gsheet)$/i', '', (string) $file['original_filename']) . '.' . $wantExt;
    attach_exported_file($pdo, (int) $file['document_id'], $tmp, $name, $mime, 'google-export');
    if (str_contains($tmp, 'gexp-')) {
        @unlink($tmp);
    }
    return true;
}

function materialize_document_google(PDO $pdo, int $docId): int
{
    $stmt = $pdo->prepare('SELECT * FROM files WHERE document_id = ?');
    $stmt->execute([$docId]);
    $n = 0;
    foreach ($stmt->fetchAll() as $file) {
        $name = strtolower((string) ($file['original_filename'] ?? ''));
        if (!str_ends_with($name, '.gdoc') && !str_ends_with($name, '.gsheet')) {
            continue;
        }
        if (materialize_google_file($pdo, $file)) {
            $n++;
        }
    }
    return $n;
}

function promote_document_files(PDO $pdo, int $docId): void
{
    $stmt = $pdo->prepare('SELECT * FROM documents WHERE id = ?');
    $stmt->execute([$docId]);
    $doc = $stmt->fetch();
    if (!$doc || ($doc['review_status'] ?? '') === 'out_of_scope') {
        return;
    }
    $year = substr((string) ($doc['doc_date'] ?: $doc['created_at'] ?: date('Y-m-d')), 0, 4);
    if (!preg_match('/^\d{4}$/', $year)) {
        $year = date('Y');
    }
    $dirRel = 'library/' . $year;
    $dir = storage_path($dirRel);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $files = $pdo->prepare('SELECT * FROM files WHERE document_id = ? ORDER BY page_no, id');
    $files->execute([$docId]);
    $upd = $pdo->prepare('UPDATE files SET stored_path = ?, drive_path = COALESCE(?, drive_path) WHERE id = ?');
    foreach ($files->fetchAll() as $file) {
        $stored = str_replace('\\', '/', (string) $file['stored_path']);
        $ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION) ?: 'bin');
        $page = (int) ($file['page_no'] ?: 1);
        $destRel = $dirRel . '/' . $docId . '-p' . $page . '.' . $ext;
        $destFull = storage_path($destRel);
        $srcFull = storage_path($stored);
        if ($stored !== $destRel && is_file($srcFull)) {
            if (!@rename($srcFull, $destFull) && @copy($srcFull, $destFull)) {
                @unlink($srcFull);
            }
        }
        $driveRel = null;
        if (is_file($destFull) && ($doc['review_status'] ?? '') === 'confirmed') {
            $driveRel = copy_to_organizer_drive($doc, $file, $destFull, $ext);
        }
        if ($stored !== $destRel || $driveRel) {
            $upd->execute([$destRel, $driveRel, (int) $file['id']]);
        }
    }
}

function copy_to_organizer_drive(array $doc, array $file, string $srcFull, string $ext): ?string
{
    $root = drive_root();
    if (!is_dir($root)) {
        return null;
    }
    $year = substr((string) ($doc['doc_date'] ?: $doc['created_at'] ?: date('Y-m-d')), 0, 4);
    $folder = type_folder($doc['doc_type'] ?? null);
    $date = substr((string) ($doc['doc_date'] ?: date('Y-m-d')), 0, 10);
    $slug = organizer_slug((string) ($doc['title'] ?: 'doc'));
    $type = $doc['doc_type'] ?: 'doc';
    $rel = '00-Organizer/' . $year . '/' . $folder . '/' . $date . '_' . $type . '_' . $slug . '.' . $ext;
    $dest = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }
    if (@copy($srcFull, $dest)) {
        return $rel;
    }
    return null;
}

function doc_types(): array
{
    return [
        'tax_notice', 'tax_return', 'utility_bill', 'medical_record', 'medical_bill',
        'appointment', 'insurance', 'bank_letter', 'bank_statement', 'id_document',
        'housing_lease', 'housing_notice', 'court_summons', 'court_filing',
        'correspondence', 'receipt', 'photo_evidence', 'serial_plate', 'other',
    ];
}

function link_kinds(): array
{
    return ['exhibit', 'evidence', 'reply', 'supersedes', 'related'];
}

function set_document_tags(PDO $pdo, int $docId, string $raw): void
{
    $names = [];
    foreach (preg_split('/[,;]+/', $raw) ?: [] as $n) {
        $n = strtolower(trim($n));
        $n = preg_replace('/[^a-z0-9-]+/', '-', $n) ?? '';
        $n = trim($n, '-');
        if ($n !== '') {
            $names[] = $n;
        }
    }
    $names = array_values(array_unique($names));
    $pdo->prepare('DELETE FROM document_tags WHERE document_id = ?')->execute([$docId]);
    $insT = $pdo->prepare('INSERT IGNORE INTO tags (name) VALUES (?)');
    $insD = $pdo->prepare(
        'INSERT INTO document_tags (document_id, tag_id) SELECT ?, id FROM tags WHERE name = ?'
    );
    foreach ($names as $n) {
        $insT->execute([$n]);
        $insD->execute([$docId, $n]);
    }
}

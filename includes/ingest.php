<?php

declare(strict_types=1);

function store_upload_group(PDO $pdo, array $fileBag, string $mode, string $notes, string $source): array
{
    $inbox = storage_path('inbox');
    if (!is_dir($inbox) && !mkdir($inbox, 0775, true) && !is_dir($inbox)) {
        throw new RuntimeException('Could not create inbox folder.');
    }

    $accepted = [];
    $skipped = [];
    $errors = [];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $count = isset($fileBag['name']) && is_array($fileBag['name']) ? count($fileBag['name']) : 0;

    for ($i = 0; $i < $count; $i++) {
        $name = (string) ($fileBag['name'][$i] ?? 'file');
        $err = (int) ($fileBag['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            $errors[] = $name . ' failed (upload code ' . $err . ').';
            continue;
        }
        $tmp = (string) $fileBag['tmp_name'][$i];
        if (!is_uploaded_file($tmp)) {
            $errors[] = $name . ' was not a valid upload.';
            continue;
        }
        $size = (int) $fileBag['size'][$i];
        $mime = $finfo->file($tmp) ?: ((string) ($fileBag['type'][$i] ?? 'application/octet-stream'));
        $hash = hash_file('sha256', $tmp);

        $dup = $pdo->prepare('SELECT id FROM files WHERE sha256 = ? LIMIT 1');
        $dup->execute([$hash]);
        if ($dup->fetch()) {
            $skipped[] = $name . ' is already in the archive.';
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $safeExt = preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'bin';
        $storedName = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $safeExt;
        $relative = 'inbox/' . $storedName;
        $dest = storage_path($relative);
        if (!move_uploaded_file($tmp, $dest)) {
            $errors[] = 'Could not store ' . $name;
            continue;
        }

        $accepted[] = [
            'original' => $name,
            'relative' => $relative,
            'mime' => $mime,
            'size' => $size,
            'hash' => $hash,
        ];
    }

    if (!$accepted) {
        return ['documents' => [], 'skipped' => $skipped, 'errors' => $errors ?: ['No new files to save.']];
    }

    $notes = trim($notes);
    $merge = ($mode === 'merge');
    $created = [];

    $pdo->beginTransaction();
    try {
        if ($merge) {
            $title = $notes !== '' ? title_from_notes($notes) : merge_title($accepted);
            $pdo->prepare('INSERT INTO documents (title, notes, review_status) VALUES (?, ?, ?)')
                ->execute([$title, $notes !== '' ? $notes : null, 'inbox']);
            $docId = (int) $pdo->lastInsertId();
            $page = 1;
            foreach ($accepted as $item) {
                insert_file_row($pdo, $docId, $item, $page, $source);
                $page++;
            }
            $created[] = ['id' => $docId, 'title' => $title, 'pages' => count($accepted)];
        } else {
            foreach ($accepted as $item) {
                $title = $notes !== '' ? title_from_notes($notes) : $item['original'];
                $pdo->prepare('INSERT INTO documents (title, notes, review_status) VALUES (?, ?, ?)')
                    ->execute([$title, $notes !== '' ? $notes : null, 'inbox']);
                $docId = (int) $pdo->lastInsertId();
                insert_file_row($pdo, $docId, $item, 1, $source);
                $created[] = ['id' => $docId, 'title' => $title, 'pages' => 1];
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    foreach ($created as $doc) {
        ocr_document_files($pdo, (int) $doc['id']);
    }

    return ['documents' => $created, 'skipped' => $skipped, 'errors' => $errors];
}

function insert_file_row(PDO $pdo, int $docId, array $item, int $page, string $source): void
{
    $pdo->prepare(
        'INSERT INTO files (document_id, original_filename, stored_path, mime, byte_size, sha256, page_no, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $docId,
        $item['original'],
        $item['relative'],
        $item['mime'],
        $item['size'],
        $item['hash'],
        $page,
        $source,
    ]);
    $fid = (int) $pdo->lastInsertId();
    $stub = parse_google_stub(storage_path($item['relative']));
    if ($stub && $fid > 0) {
        $pdo->prepare('UPDATE files SET drive_file_id = ? WHERE id = ?')->execute([$stub['id'], $fid]);
    }
}

function title_from_notes(string $notes): string
{
    $line = preg_split("/\R/", $notes)[0] ?? $notes;
    $line = trim($line);
    if ($line === '') {
        return 'Untitled scan';
    }
    return mb_substr($line, 0, 120);
}

function merge_title(array $accepted): string
{
    $n = count($accepted);
    if ($n === 1) {
        return $accepted[0]['original'];
    }
    return $n . '-page scan';
}

function organizer_tool(string $name): ?string
{
    global $config;
    $configured = trim((string) ($config[$name] ?? ''));
    if ($configured !== '' && is_file($configured)) {
        return $configured;
    }
    $guess = [
        'tesseract' => [
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
            getenv('LOCALAPPDATA') . '\\OrganizerOCR\\Tesseract-OCR\\tesseract.exe',
        ],
        'pdftotext' => [
            'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe',
        ],
        'pdftoppm' => [
            getenv('LOCALAPPDATA') . '\\OrganizerOCR\\poppler\\poppler-24.08.0\\Library\\bin\\pdftoppm.exe',
            'C:\\Program Files\\poppler\\Library\\bin\\pdftoppm.exe',
            'C:\\poppler\\Library\\bin\\pdftoppm.exe',
        ],
    ];
    foreach ($guess[$name] ?? [] as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

function run_tool(array $args): string
{
    $cmd = implode(' ', array_map('escapeshellarg', $args));
    $out = [];
    $code = 0;
    exec($cmd . ' 2>NUL', $out, $code);
    return trim(implode("\n", $out));
}

function ocr_document_files(PDO $pdo, int $docId): void
{
    if (!column_exists($pdo, 'files', 'ocr_text')) {
        return;
    }
    $stmt = $pdo->prepare('SELECT * FROM files WHERE document_id = ? ORDER BY page_no, id');
    $stmt->execute([$docId]);
    foreach ($stmt->fetchAll() as $file) {
        ocr_one_file($pdo, $file);
    }
}

function ocr_one_file(PDO $pdo, array $file): void
{
    $full = storage_path((string) $file['stored_path']);
    if (!is_file($full) || filesize($full) > 15 * 1024 * 1024) {
        mark_ocr($pdo, (int) $file['id'], 'skipped', null);
        return;
    }
    $mime = (string) ($file['mime'] ?? '');
    $text = '';
    $status = 'skipped';
    try {
        if (str_starts_with($mime, 'image/')) {
            $tess = organizer_tool('tesseract');
            if (!$tess) {
                mark_ocr($pdo, (int) $file['id'], 'skipped', null);
                return;
            }
            $text = run_tool([$tess, $full, 'stdout', '-l', 'eng', '--psm', '3']);
            $status = $text === '' ? 'empty' : 'ok';
        } elseif (str_contains($mime, 'wordprocessingml') || str_ends_with(strtolower((string) $file['stored_path']), '.docx')) {
            $text = extract_docx_text($full);
            $status = $text === '' ? 'empty' : 'ok';
        } elseif ($mime === 'application/pdf' || str_ends_with(strtolower((string) $file['stored_path']), '.pdf')) {
            $pdftotext = organizer_tool('pdftotext');
            if ($pdftotext) {
                $text = run_tool([$pdftotext, '-enc', 'UTF-8', $full, '-']);
            }
            if (strlen(preg_replace('/\s+/', '', $text)) < 40) {
                $tess = organizer_tool('tesseract');
                $ppm = organizer_tool('pdftoppm');
                if ($tess && $ppm) {
                    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orgocr-' . bin2hex(random_bytes(4));
                    mkdir($dir, 0775, true);
                    run_tool([$ppm, '-png', '-f', '1', '-l', '12', $full, $dir . DIRECTORY_SEPARATOR . 'p']);
                    $pages = glob($dir . DIRECTORY_SEPARATOR . 'p*.png') ?: [];
                    $bits = [];
                    foreach ($pages as $png) {
                        $bits[] = run_tool([$tess, $png, 'stdout', '-l', 'eng', '--psm', '3']);
                        @unlink($png);
                    }
                    @rmdir($dir);
                    $ocr = trim(implode("\n", $bits));
                    if (strlen($ocr) > strlen($text)) {
                        $text = $ocr;
                    }
                }
            }
            $status = $text === '' ? ($pdftotext ? 'empty' : 'skipped') : 'ok';
        } else {
            mark_ocr($pdo, (int) $file['id'], 'skipped', null);
            return;
        }
    } catch (Throwable $e) {
        mark_ocr($pdo, (int) $file['id'], 'error', null);
        return;
    }
    mark_ocr($pdo, (int) $file['id'], $status, $text !== '' ? $text : null);
}

function mark_ocr(PDO $pdo, int $fileId, string $status, ?string $text): void
{
    $pdo->prepare('UPDATE files SET ocr_text = ?, ocr_status = ?, ocr_at = NOW() WHERE id = ?')
        ->execute([$text, $status, $fileId]);
}

function extract_docx_text(string $path): string
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!is_string($xml) || $xml === '') {
        return '';
    }
    $xml = str_replace('</w:p>', "\n", $xml);
    $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return trim($text);
}

<?php

declare(strict_types=1);

function fintable_token(): string
{
    global $config;
    $fromConfig = trim((string) ($config['fintable_token'] ?? ''));
    if ($fromConfig !== '') {
        return $fromConfig;
    }
    $path = storage_path('fintable.token');
    if (is_file($path)) {
        return trim((string) file_get_contents($path));
    }
    return '';
}

function fintable_configured(): bool
{
    return fintable_token() !== '';
}

function fintable_save_token(string $token): void
{
    $token = trim($token);
    $path = storage_path('fintable.token');
    if ($token === '') {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }
    file_put_contents($path, $token);
}

/** @param array<string, scalar|array|null> $query */
function fintable_request(string $path, array $query = []): array
{
    $token = fintable_token();
    if ($token === '') {
        throw new RuntimeException('No Fintable token');
    }
    global $config;
    $base = rtrim((string) ($config['fintable_base'] ?? 'https://fintable.io/api/v2'), '/');
    $url = $base . $path;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'User-Agent: organizer-local/1.0 (first-party)',
    ];
    $raw = null;
    $code = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Fintable request failed: ' . $err);
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 45,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        if ($raw === false) {
            throw new RuntimeException('Fintable request failed');
        }
    }
    $json = json_decode((string) $raw, true);
    if ($code >= 400) {
        $msg = is_array($json) ? (string) ($json['error']['message'] ?? $json['message'] ?? $raw) : (string) $raw;
        throw new RuntimeException('Fintable HTTP ' . $code . ': ' . mb_substr($msg, 0, 240));
    }
    return is_array($json) ? $json : [];
}

function fintable_sync_accounts(PDO $pdo): int
{
    $json = fintable_request('/accounts');
    $rows = $json['data'] ?? [];
    if (!is_array($rows)) {
        return 0;
    }
    $n = 0;
    $sql = 'INSERT INTO bank_accounts
            (fintable_id, connection_id, institution, name, display_name, type, currency, balance, balance_available, enabled, extra_json, synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                connection_id = VALUES(connection_id),
                institution = VALUES(institution),
                name = VALUES(name),
                display_name = VALUES(display_name),
                type = VALUES(type),
                currency = VALUES(currency),
                balance = VALUES(balance),
                balance_available = VALUES(balance_available),
                enabled = VALUES(enabled),
                extra_json = VALUES(extra_json),
                synced_at = VALUES(synced_at)';
    $stmt = $pdo->prepare($sql);
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['id'])) {
            continue;
        }
        $stmt->execute([
            (string) $row['id'],
            $row['connection_id'] ?? null,
            $row['institution_name'] ?? ($row['institution'] ?? null),
            (string) ($row['name'] ?? 'Account'),
            $row['display_name'] ?? null,
            $row['type'] ?? null,
            (string) ($row['currency'] ?? 'USD'),
            isset($row['balance']) && $row['balance'] !== null ? $row['balance'] : null,
            isset($row['balance_available']) && $row['balance_available'] !== null ? $row['balance_available'] : null,
            !empty($row['enabled']) ? 1 : 0,
            extra_encode($row),
        ]);
        $n++;
    }
    return $n;
}

function fintable_local_account_map(PDO $pdo): array
{
    $map = [];
    foreach ($pdo->query('SELECT id, fintable_id FROM bank_accounts')->fetchAll() as $row) {
        $map[(string) $row['fintable_id']] = (int) $row['id'];
    }
    return $map;
}

function fintable_sync_transactions(PDO $pdo, ?string $updatedSince = null): int
{
    $map = fintable_local_account_map($pdo);
    $n = 0;
    $cursor = null;
    $pages = 0;
    $sql = 'INSERT INTO bank_transactions
            (fintable_id, account_id, posted_on, amount, currency, description, merchant, category, extra_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                account_id = VALUES(account_id),
                posted_on = VALUES(posted_on),
                amount = VALUES(amount),
                currency = VALUES(currency),
                description = VALUES(description),
                merchant = VALUES(merchant),
                category = VALUES(category),
                extra_json = VALUES(extra_json)';
    $stmt = $pdo->prepare($sql);
    do {
        $query = ['limit' => 100];
        if ($updatedSince) {
            $query['order'] = 'updated';
            $query['updated_since'] = $updatedSince;
        }
        if ($cursor) {
            $query['cursor'] = $cursor;
        }
        $json = fintable_request('/transactions', $query);
        $rows = $json['data'] ?? [];
        if (!is_array($rows)) {
            break;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $ftAcct = (string) ($row['account_id'] ?? '');
            $local = $map[$ftAcct] ?? 0;
            if ($local < 1) {
                continue;
            }
            $date = (string) ($row['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $cat = null;
            if (isset($row['category']) && is_array($row['category'])) {
                $cat = (string) ($row['category']['name'] ?? '');
            } elseif (isset($row['category']) && is_string($row['category'])) {
                $cat = $row['category'];
            }
            $stmt->execute([
                (string) $row['id'],
                $local,
                $date,
                $row['amount'] ?? '0',
                (string) ($row['currency'] ?? 'USD'),
                mb_substr((string) ($row['description'] ?? ''), 0, 255) ?: null,
                isset($row['merchant']) ? mb_substr((string) $row['merchant'], 0, 180) : null,
                $cat !== null && $cat !== '' ? mb_substr($cat, 0, 80) : null,
                extra_encode([
                    'ext_id' => $row['ext_id'] ?? null,
                    'datetime' => $row['datetime'] ?? null,
                ]),
            ]);
            $n++;
        }
        $cursor = $json['next_cursor'] ?? null;
        $pages++;
    } while (is_string($cursor) && $cursor !== '' && $pages < 50);
    app_state_set($pdo, 'fintable_last_sync', date('c'));
    app_state_set($pdo, 'fintable_tx_count', (string) $n);
    return $n;
}

/** @return array{accounts:int, transactions:int, error:?string} */
function fintable_sync_all(PDO $pdo): array
{
    $out = ['accounts' => 0, 'transactions' => 0, 'error' => null];
    try {
        $out['accounts'] = fintable_sync_accounts($pdo);
        $since = app_state_get($pdo, 'fintable_last_sync');
        $out['transactions'] = fintable_sync_transactions($pdo, $since);
        if (function_exists('refresh_money_after_sync')) {
            refresh_money_after_sync($pdo);
        }
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

function money_fmt(?string $n, string $currency = 'USD'): string
{
    if ($n === null || $n === '') {
        return '—';
    }
    $v = (float) $n;
    $sign = $v < 0 ? '-' : '';
    return $sign . '$' . number_format(abs($v), 2);
}

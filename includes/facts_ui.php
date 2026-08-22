<?php

declare(strict_types=1);

function fact_return_url(): string
{
    $r = (string) ($_POST['return'] ?? 'facts.php');
    if ($r === 'facts.php') {
        return 'facts.php';
    }
    if (preg_match('/^(document|case|entity)\.php\?id=\d+$/', $r)) {
        return $r;
    }
    if ($r === 'index.php' || $r === 'index.php?view=library') {
        return $r;
    }
    return 'facts.php';
}

/** @return list<string> */
function fact_options(array $fact): array
{
    $raw = $fact['options_json'] ?? null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $opt) {
                if (is_string($opt) && trim($opt) !== '') {
                    $out[] = trim($opt);
                } elseif (is_array($opt) && isset($opt['label'])) {
                    $out[] = trim((string) $opt['label']);
                }
            }
            if ($out) {
                return array_values(array_unique($out));
            }
        }
    }
    $value = (string) ($fact['fact_value'] ?? '');
    if (preg_match('/\s+vs\.?\s+/i', $value)) {
        $parts = preg_split('/\s+vs\.?\s+/i', $value) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
        if (count($parts) >= 2) {
            return $parts;
        }
    }
    return [];
}

function fact_question(array $fact): string
{
    $prompt = trim((string) ($fact['prompt'] ?? ''));
    if ($prompt !== '') {
        return $prompt;
    }
    $key = str_replace('_', ' ', (string) ($fact['fact_key'] ?? 'this'));
    return 'Which is right for ' . $key . '?';
}

function apply_fact_choice(PDO $pdo, int $id, string $action, string $choice, string $custom): bool
{
    if ($id < 1) {
        return false;
    }
    if ($action === 'rejected') {
        $stmt = $pdo->prepare(
            'UPDATE untrusted_facts
             SET status = ?, resolved_at = NOW(), resolved_note = ?
             WHERE id = ? AND status = ?'
        );
        $stmt->execute(['rejected', $custom !== '' ? $custom : 'None of these', $id, 'open']);
        return $stmt->rowCount() > 0;
    }

    $value = $choice;
    if ($action === 'custom' || $value === '') {
        $value = $custom;
    }
    $value = trim($value);
    if ($value === '') {
        return false;
    }
    $stmt = $pdo->prepare(
        'UPDATE untrusted_facts
         SET status = ?, fact_value = ?, resolved_at = NOW(), resolved_note = ?
         WHERE id = ? AND status = ?'
    );
    $note = $action === 'custom' ? 'Typed' : 'Picked';
    $stmt->execute(['confirmed', $value, $note, $id, 'open']);
    if ($stmt->rowCount() < 1) {
        return false;
    }
    $row = $pdo->prepare('SELECT * FROM untrusted_facts WHERE id = ?');
    $row->execute([$id]);
    $fact = $row->fetch();
    if ($fact) {
        write_confirmed_fact($pdo, $fact, $value);
        maybe_confirm_document($pdo, (int) ($fact['document_id'] ?? 0));
    }
    return true;
}

function handle_fact_post(PDO $pdo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    $id = (int) ($_POST['id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $choice = trim((string) ($_POST['choice'] ?? ''));
    $custom = trim((string) ($_POST['custom'] ?? ''));
    if ($choice !== '' && $action === '') {
        $action = 'picked';
    }
    if ($id > 0 && $action !== '') {
        apply_fact_choice($pdo, $id, $action, $choice, $custom);
    }
    header('Location: ' . fact_return_url(), true, 303);
    exit;
}

function render_fact_form(array $fact, string $return = 'facts.php'): void
{
    $options = fact_options($fact);
    $qid = (int) $fact['id'];
    ?>
    <form method="post" action="facts.php" class="fact-form">
        <input type="hidden" name="id" value="<?= $qid ?>">
        <input type="hidden" name="return" value="<?= h($return) ?>">
        <?php if ($options): ?>
            <div class="fact-choices" role="group" aria-label="Choices">
                <?php foreach ($options as $opt): ?>
                    <button type="submit" class="choice" name="choice" value="<?= h($opt) ?>"><?= h($opt) ?></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="fact-custom">
            <label class="visually-hidden" for="custom-<?= $qid ?>">Or type the correct one</label>
            <input id="custom-<?= $qid ?>" type="text" name="custom" placeholder="Or type the correct one" autocomplete="off">
            <button class="btn small" type="submit" name="action" value="custom">Save</button>
            <button class="btn small ghost" type="submit" name="action" value="rejected">None of these</button>
        </div>
    </form>
    <?php
}

function render_fact_card(array $fact, string $return = 'facts.php'): void
{
    $important = ($fact['importance'] ?? '') === 'important';
    ?>
    <li class="<?= $important ? 'untrusted important' : 'untrusted' ?>">
        <?php if ($important): ?>
            <p class="meta">Important</p>
        <?php endif; ?>
        <p class="fact-q"><?= h(fact_question($fact)) ?></p>
        <?php if (!empty($fact['reason'])): ?>
            <p class="muted"><?= h((string) $fact['reason']) ?></p>
        <?php endif; ?>
        <?php if (!empty($fact['doc_title']) && !empty($fact['document_id'])): ?>
            <p class="muted"><a href="document.php?id=<?= (int) $fact['document_id'] ?>"><?= h((string) $fact['doc_title']) ?></a></p>
        <?php endif; ?>
        <?php render_fact_form($fact, $return); ?>
    </li>
    <?php
}

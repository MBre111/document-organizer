<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/facts_ui.php';

$pdo = db();
handle_fact_post($pdo);

$open = $pdo->query(
    "SELECT u.*, d.title AS doc_title
     FROM untrusted_facts u
     LEFT JOIN documents d ON d.id = u.document_id
     WHERE u.status = 'open'
     ORDER BY FIELD(u.importance, 'important', 'normal') , u.id"
)->fetchAll();

render_header('Untrusted facts', 'facts');
?>
<h1>Needs confirming</h1>
<p class="lede">Tap the bubble that is true. Type if none fit. “None of these” marks it false. Important items first.</p>

<?php if (!$open): ?>
    <p class="muted">Nothing waiting.</p>
<?php else: ?>
    <ul class="facts">
        <?php foreach ($open as $fact): ?>
            <?php render_fact_card($fact, 'facts.php'); ?>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php
render_footer();

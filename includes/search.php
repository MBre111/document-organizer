<?php

declare(strict_types=1);

function search_like(string $q): string
{
    $q = str_replace(['%', '_'], '', $q);
    return '%' . $q . '%';
}

/** @return array{q:string, documents:list, entities:list, journals:list, deadlines:list} */
function search_all(PDO $pdo, string $q, int $limit = 8): array
{
    $q = trim($q);
    $out = [
        'q' => $q,
        'documents' => [],
        'entities' => [],
        'journals' => [],
        'deadlines' => [],
    ];
    if (mb_strlen($q) < 2) {
        return $out;
    }
    $like = search_like($q);
    $lim = max(1, min(20, $limit));

    $sql = "SELECT DISTINCT d.id, d.title, d.doc_type, d.source_org, d.doc_date, d.review_status, d.summary
            FROM documents d
            LEFT JOIN document_tags dt ON dt.document_id = d.id
            LEFT JOIN tags t ON t.id = dt.tag_id
            LEFT JOIN document_entities de ON de.document_id = d.id
            LEFT JOIN entities e ON e.id = de.entity_id
            LEFT JOIN files f ON f.document_id = d.id
            LEFT JOIN cases c ON c.id = d.case_id
            WHERE d.review_status <> 'out_of_scope' AND (
                d.title LIKE ? OR d.summary LIKE ? OR d.source_org LIKE ?
                OR d.notes LIKE ? OR CAST(d.extra_json AS CHAR) LIKE ?
                OR t.name LIKE ? OR e.name LIKE ?
                OR c.case_number LIKE ? OR f.ocr_text LIKE ?
            )
            ORDER BY COALESCE(d.doc_date, d.created_at) DESC, d.id DESC
            LIMIT {$lim}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$like, $like, $like, $like, $like, $like, $like, $like, $like]);
    $out['documents'] = $stmt->fetchAll();

    if (table_exists($pdo, 'entities')) {
        $stmt = $pdo->prepare(
            "SELECT id, kind, name, LEFT(notes, 160) AS preview
             FROM entities
             WHERE name LIKE ? OR notes LIKE ? OR CAST(extra_json AS CHAR) LIKE ?
             ORDER BY kind, name
             LIMIT {$lim}"
        );
        $stmt->execute([$like, $like, $like]);
        $out['entities'] = $stmt->fetchAll();
    }

    if (table_exists($pdo, 'journals')) {
        $stmt = $pdo->prepare(
            "SELECT id, entry_date, title, LEFT(body, 180) AS preview
             FROM journals
             WHERE title LIKE ? OR body LIKE ?
             ORDER BY entry_date DESC, id DESC
             LIMIT {$lim}"
        );
        $stmt->execute([$like, $like]);
        $out['journals'] = $stmt->fetchAll();
    }

    if (table_exists($pdo, 'deadlines')) {
        $stmt = $pdo->prepare(
            "SELECT id, due_on, kind, title, status
             FROM deadlines
             WHERE title LIKE ? OR kind LIKE ?
             ORDER BY (status = 'open') DESC, due_on DESC
             LIMIT {$lim}"
        );
        $stmt->execute([$like, $like]);
        $out['deadlines'] = $stmt->fetchAll();
    }

    return $out;
}

function search_total(array $hits): int
{
    return count($hits['documents']) + count($hits['entities']) + count($hits['journals']) + count($hits['deadlines']);
}

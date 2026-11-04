<?php
// Group by patient identity, never by name: different residents can share a name.
function dentalGroupExaminations(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        return strcmp((string)($b['exam_date'] ?? ''), (string)($a['exam_date'] ?? ''))
            ?: ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
    });
    $groups = [];
    foreach ($rows as $index => $row) {
        $residentId = (int)($row['resident_id'] ?? 0);
        $key = $residentId > 0 ? 'resident-' . $residentId : 'unlinked-' . $index;
        $groups[$key][] = $row;
    }
    return array_values($groups);
}

// Each completed entry is a separate historical record, even for the same visit/type.
function dentalInsertHistoricalRecord(PDO $pdo, array $row): int
{
    $meta = $row['meta'] ?? null;
    if (is_array($meta)) $meta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $statement = $pdo->prepare('INSERT INTO dental_records
        (record_type, resident_id, walkin_name, record_date, title, tooth_numbers,
         findings, notes, status, priority, meta_json, performed_by)
        VALUES (:type, :rid, :wn, :rd, :title, :teeth, :findings, :notes, :status, :priority, :meta, :by)');
    $statement->execute([
        'type'=>(string)($row['record_type'] ?? 'other'), 'rid'=>(int)($row['resident_id'] ?? 0) ?: null,
        'wn'=>$row['walkin_name'] ?? null, 'rd'=>$row['record_date'] ?? date('Y-m-d'),
        'title'=>$row['title'] ?? null, 'teeth'=>$row['tooth_numbers'] ?? null,
        'findings'=>$row['findings'] ?? null, 'notes'=>$row['notes'] ?? null,
        'status'=>$row['status'] ?? 'Completed', 'priority'=>$row['priority'] ?? null,
        'meta'=>$meta, 'by'=>(int)($row['performed_by'] ?? 0) ?: null,
    ]);
    return (int)$pdo->lastInsertId();
}

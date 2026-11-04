<?php
// Shared one-time RHU intake and per-visit fields for staff workflows.
function rhuIntakeEnsure(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS resident_rhu_intakes (
        resident_id BIGINT UNSIGNED PRIMARY KEY, details LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function rhuIntakeFields(array $omit = [], string $identityHtml = ''): string {
    $input = static function (string $key, string $label): string {
        return '<label class="rhu-paper-field"><span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span><input name="rhu_intake[' . $key . ']" maxlength="150" autocomplete="off"></label>';
    };
    $choices = static function (string $key, string $label, array $options): string {
        $html = '<fieldset class="rhu-paper-choices"><legend>' . $label . '</legend>';
        foreach ($options as $value) {
            $safe = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            $html .= '<label><input type="radio" name="rhu_intake[' . $key . ']" value="' . $safe . '"> ' . $safe . '</label>';
        }
        return $html . '<label><input type="radio" name="rhu_intake[' . $key . ']" value="" checked> Not recorded</label></fieldset>';
    };
    $html = '<style>
    .rhu-paper{grid-column:1/-1;min-width:0;border:1px solid #cbd5e1;border-radius:8px;padding:20px;background:#fff;color:#17212b}
    .rhu-paper-header{text-align:center;border-bottom:1px solid #cbd5e1;padding-bottom:14px;margin-bottom:16px;line-height:1.5}
    .rhu-paper-header strong{display:block;font-size:18px;letter-spacing:.04em}.rhu-paper-header span{font-size:14px}
    .rhu-paper-note{font-size:12px;color:#64748b;margin:0 0 18px;line-height:1.5}
    .rhu-paper-identity{display:flex;flex-direction:column;gap:12px}.rhu-paper-identity input,.rhu-paper-identity select{min-width:0;max-width:100%}.rhu-paper-columns{display:grid;grid-template-columns:1fr 1fr;gap:28px}.rhu-paper-column{display:flex;flex-direction:column;gap:18px;min-width:0}
    .rhu-paper-field{display:flex;flex-wrap:wrap;align-items:baseline;gap:6px;font-size:13px;font-weight:600}
    .rhu-paper-field input{flex:1 1 100px;width:100%;min-width:0;border:0;border-bottom:1px solid #64748b;border-radius:0;background:#fff;padding:6px;color:#0f172a;font:inherit;font-weight:400}
    .rhu-paper input:focus-visible{outline:2px solid #0d9488;outline-offset:3px}
    .rhu-paper-choices{border:0;padding:0;min-width:0;font-size:12px}.rhu-paper-choices legend{font-weight:600;margin-bottom:8px}.rhu-paper-choices label{display:inline-flex;align-items:center;gap:4px;margin:0 12px 8px 0;font-weight:400}.rhu-paper-choices input{accent-color:#0d9488}
    @media(max-width:640px){.rhu-paper{padding:14px}.rhu-paper-columns{grid-template-columns:1fr;gap:18px}}
    </style><fieldset class="rhu-paper"><legend class="font-bold">RHU patient intake &mdash; complete once</legend><div class="rhu-paper-header"><strong>RURAL HEALTH UNIT</strong><span>Nasugbu, Batangas</span></div><p class="rhu-paper-note">New resident recording. Age is calculated from birth date. These additional details are optional for all residents, including children and teens; leave unknown or inapplicable details blank.</p><div class="rhu-paper-columns"><div class="rhu-paper-column">';
    if ($identityHtml !== '') $html .= '<div class="rhu-paper-identity">' . $identityHtml . '</div>';
    foreach (['middle_name'=>'Middle Name', 'spouse_name'=>"Spouse Full Name", 'maiden_name'=>'Maiden name (if married)', 'parent_name'=>"Parent Full Name", 'philhealth_number'=>'PhilHealth No.'] as $key=>$label) if (!in_array($key, $omit, true)) $html .= $input($key, $label);
    $html .= $choices('membership', 'PhilHealth membership', ['Member', 'Dependent']);
    $html .= '</div><div class="rhu-paper-column">';
    foreach (['civil_status'=>'Civil Status (CS)', 'birthplace'=>'Birthplace', 'education'=>'Educational Attainment', 'employment'=>'Employment Status', 'tin'=>'TIN No.'] as $key=>$label) if (!in_array($key, $omit, true)) $html .= $input($key, $label);
    $html .= $choices('dswd_nhts', 'DSWD NHTS Member', ['Yes', 'No']);
    $html .= $choices('category', 'Type of PhilHealth', ['Indigent', 'Formal Economy', 'Non-Formal Economy', 'Self Employed', 'Senior Citizen']);
    return $html . '</div></div></fieldset>';
}
function rhuIntakeSave(PDO $pdo, int $residentId, array $post): void {
    if ($residentId <= 0 || !isset($post['rhu_intake']) || !is_array($post['rhu_intake'])) return;
    $allowed = ['middle_name','civil_status','spouse_name','maiden_name','parent_name','birthplace','education','employment','tin','philhealth_number','membership','category','dswd_nhts'];
    $details = [];
    foreach ($allowed as $key) {
        $value = $post['rhu_intake'][$key] ?? '';
        if (!is_scalar($value) || strlen((string)$value) > 600) throw new InvalidArgumentException('Invalid RHU intake field: ' . $key);
        $details[$key] = trim((string)$value);
    }
    // Existing intake is retained when a resident returns or is matched by duplicate detection.
    $stmt = $pdo->prepare('INSERT IGNORE INTO resident_rhu_intakes (resident_id, details) VALUES (?, ?)');
    $stmt->execute([$residentId, json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    // Keep shared identity columns available to all existing department views.
    $columns = $pdo->query('SHOW COLUMNS FROM residents')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['middle_name'=>'middle_name', 'civil_status'=>'civil_status', 'philhealth_number'=>'philhealth_number'] as $key=>$column) {
        if ($details[$key] !== '' && in_array($column, $columns, true)) {
            $update = $pdo->prepare("UPDATE residents SET `$column` = ? WHERE id = ? AND (`$column` IS NULL OR TRIM(`$column`) = '')");
            $update->execute([$details[$key], $residentId]);
        }
    }

}
function rhuVisitFields(array $omit = []): string {
    $html = '<fieldset class="sm:col-span-2 space-y-3 rounded-2xl border border-slate-200 p-4"><legend class="font-bold">Current visit vital signs</legend><p class="text-xs text-slate-500">Record available measurements taken today. Each measurement is optional; leave unmeasured values blank. Saved resident information is reused.</p><div class="grid grid-cols-2 gap-3">';
    foreach (['bp'=>'Blood pressure (mmHg)', 'temperature'=>'Temperature (°C)', 'rr'=>'Respiratory rate (/min)', 'pr'=>'Pulse rate (bpm)', 'height'=>'Height (cm)', 'weight'=>'Weight (kg)'] as $key=>$label) {
        if (in_array($key, $omit, true)) continue;
        $type = $key === 'bp' ? 'text' : 'number';
        $html .= '<label class="block text-xs font-bold">' . $label . ' <span class="font-normal text-slate-500">(optional)</span><input name="rhu_visit[' . $key . ']" type="' . $type . '" ' . ($type === 'number' ? 'min="0" step="any"' : 'maxlength="20" placeholder="120/80"') . ' class="mt-1 w-full rounded-xl border border-slate-300 p-2.5"></label>';
    }
    return $html . '</div></fieldset>';
}
function rhuVisitSave(PDO $pdo, int $residentId, array $post, string $department, int $staffId): void {
    if ($residentId <= 0) return;
    $v = $post['rhu_visit'] ?? [];
    if (!is_array($v)) throw new InvalidArgumentException('Invalid visit measurements.');
    if (!array_filter($v, static fn($value) => is_scalar($value) && trim((string)$value) !== '')) return;
    $notes = [];
    foreach (['rr'=>'Respiratory rate', 'pr'=>'Pulse rate'] as $key=>$label) {
        if (isset($v[$key]) && $v[$key] !== '') $notes[] = $label . ': ' . (float)$v[$key];
    }
    $id = portalSaveHealthRecordEntry($pdo, $residentId, [
        'record_type'=>$department . ' visit vitals', 'blood_pressure'=>$v['bp'] ?? '',
        'temperature'=>$v['temperature'] ?? '', 'height_cm'=>$v['height'] ?? '',
        'weight_kg'=>$v['weight'] ?? '', 'notes'=>implode('; ', $notes), 'recorded_by_id'=>$staffId,
    ]);
    if (!$id) throw new RuntimeException('Could not save current visit measurements.');
}

function rhuIntakeSavedData(PDO $pdo): string {
    $rows = $pdo->query('SELECT resident_id, details FROM resident_rhu_intakes')->fetchAll(PDO::FETCH_KEY_PAIR);
    return '<script type="application/json" id="rhu-saved-intakes">' . json_encode($rows ?: new stdClass(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script><script src="assets/js/rhu-patient-intake.js" defer></script>';
}

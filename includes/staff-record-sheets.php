<?php
require_once dirname(__DIR__) . '/includes/dental-chart.php';
require_once dirname(__DIR__) . '/includes/dental-prescription-sheet.php';
function staffRecordBackUrl(string $department, int $residentId = 0): string
{
    if ($department === 'DENTAL') return 'DentalDashboard.php?tab=patients' . ($residentId > 0 ? '&resident_records=' . $residentId : '');
    return match($department) {
        'MEDTECH'=>'MedTechDashboard.php', 'MIDWIFE'=>'MidwifeDashboard.php', 'NURSE'=>'NurseDashboard.php?tab=patients',
        'SANITARY_INSPECTOR'=>'SanitaryDashboard.php', 'BHW'=>'rhu_index.php', default=>'RHUAdminDashboard.php',
    };
}
function staffRecordSheet(array $row, string $table, string $label, array $prescriptions = []): string
{
    $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $meta = is_array($row['meta'] ?? null) ? $row['meta'] : (json_decode((string)($row['meta_json'] ?? ''),true) ?: []);
    $type = (string)($row['record_type'] ?? '');
    $date = '';
    foreach (['record_date','consultation_date','visit_date','vaccination_date','event_date','issue_date','last_checkup_date','created_at'] as $key) {
        if (!empty($row[$key])) { $date = (string)$row[$key]; break; }
    }
    $provider = trim((string)($row['provider_name'] ?? ''));
    if ($table === 'dental_records' && $type === 'prescription') return dentalPrescriptionSheet($row, [], false, false);
    ob_start();
    if ($table === 'dental_records' && $type === 'examination') {
        $row['meta'] = $meta;
        $view = dentalRecordViewData($row);
        if ($view['caries_count'] !== null) $meta['caries_count'] = $view['caries_count'];
        ?>
        <article class="dental-exam-sheet">
            <div class="dental-sheet-chart">
                <p class="dental-sheet-label">Dental chart</p><?= dentalRecordChart($view['marks']) ?>
                <div class="sheet-color-key"><span style="--key:#ef4444">Caries</span><span style="--key:#3b82f6">Pasta</span><span style="--key:#94a3b8">Extracted (X)</span><span style="--key:#f59e0b">For extraction</span><span style="--key:#8b5cf6">Crown</span></div>
            </div>
            <div class="dental-sheet-details">
                <div class="dental-sheet-heading"><h3>Examination</h3><span class="sheet-status"><?= $escape($row['status'] ?? 'Completed') ?></span></div>
                <dl class="sheet-visit"><div><dt>Date</dt><dd><?= $escape($date ?: 'Not recorded') ?></dd></div><div><dt>Dentist</dt><dd><?= $escape($provider ?: 'Not recorded') ?></dd></div></dl>
                <?php if ($view['warnings']): ?><p role="note" style="padding:8px;border:1px solid #fbbf24;border-radius:8px;background:#fffbeb;color:#92400e;font-size:11px;line-height:1.5"><?= $escape(implode(' ', $view['warnings'])) ?></p><?php endif; ?>
                <?php if ($view['chart_summary'] !== ''): ?><div class="dental-sheet-section"><h4>Recorded chart findings</h4><p><?= $escape($view['chart_summary']) ?></p></div><?php endif; ?>
                <div class="dental-sheet-section"><h4>Findings / remarks</h4><p><?= $escape($view['findings'] ?: 'Not recorded') ?></p></div>
                <div class="dental-sheet-section"><h4>Recommendation</h4><p><?= $escape(trim((string)($row['notes'] ?? '')) ?: 'Not recorded') ?></p></div>
                <dl class="sheet-observations"><?php foreach (['oral_hygiene'=>'Oral hygiene','gum_condition'=>'Gum condition','caries_count'=>'Caries count'] as $key=>$caption): ?><div><dt><?= $escape($caption) ?></dt><dd><?= $escape($meta[$key] ?? 'Not recorded') ?></dd></div><?php endforeach; ?></dl>
                <p class="dental-sheet-footer">Unmarked teeth: no condition recorded for this examination; not confirmation of recovery. RHU Dental Clinic / Examination #<?= (int)($row['id'] ?? 0) ?></p>
            </div>
            <?php if ($prescriptions): ?><div class="sheet-prescriptions">
                <?php foreach ($prescriptions as $rx): ?><?= dentalPrescriptionSheet($rx, [], true) ?><?php endforeach; ?>
            </div><?php endif; ?>
        </article>
        <?php
    } else {
        $title = trim((string)($row['title'] ?? '')) ?: ($type !== '' ? ucwords(str_replace('_',' ',$type)) : $label);
        ?>
        <article class="service-record-sheet">
            <div class="dental-sheet-heading"><h3><?= $escape($title) ?></h3><?php if (!empty($row['status']) || !empty($row['consultation_status'])): ?><span class="sheet-status"><?= $escape($row['status'] ?? $row['consultation_status']) ?></span><?php endif; ?></div>
            <p class="sheet-record-subtitle"><?= $escape($date ?: 'Date not recorded') ?><?= $provider !== '' ? ' / ' . $escape($provider) : '' ?></p>
            <dl class="service-record-fields">
                <?php foreach ($row as $key=>$value):
                    if ($value === null || $value === '' || str_starts_with($key,'_') || str_starts_with($key,'fhsis_') || str_ends_with($key,'_id') || str_contains($key,'encrypted')
                        || in_array($key,['id','title','record_type','meta_json','provider_name','performed_by','is_confidential','confidentiality','created_at','updated_at','status','consultation_status','resident_name','patient_name','first_name','middle_name','last_name'],true)) continue;
                    if (in_array($key,['record_date','consultation_date','visit_date','vaccination_date','event_date','issue_date'],true) && (string)$value === $date) continue;
                    ?><div><dt><?= $escape(ucwords(str_replace('_',' ',$key))) ?></dt><dd><?= $escape($value) ?></dd></div>
                <?php endforeach; ?>
                <?php foreach (['dosage','frequency','duration','quantity','procedure_type','facility_name','follow_up_date'] as $key): if (!isset($meta[$key]) || !is_scalar($meta[$key]) || $meta[$key] === '') continue; ?><div><dt><?= $escape(ucwords(str_replace('_',' ',$key))) ?></dt><dd><?= $escape($meta[$key]) ?></dd></div><?php endforeach; ?>
            </dl>
            <p class="dental-sheet-footer"><?= $escape($label) ?> / Record #<?= (int)($row['id'] ?? 0) ?></p>
        </article>
        <?php
    }
    return ob_get_clean();
}

<?php
function dentalPrescriptionPlacements(array $records): array
{
    $byExam = []; $embedded = [];
    foreach ($records as $exam) {
        if (($exam['record_type'] ?? '') !== 'examination') continue;
        foreach ($records as $rx) {
            $id = (int)($rx['id'] ?? 0);
            if ($id <= 0 || isset($embedded[$id]) || ($rx['record_type'] ?? '') !== 'prescription') continue;
            if (empty($exam['resident_id']) || (int)$exam['resident_id'] !== (int)($rx['resident_id'] ?? 0)) continue;
            if (empty($exam['record_date']) || substr($exam['record_date'],0,10) !== substr((string)($rx['record_date'] ?? ''),0,10)) continue;
            $byExam[(int)$exam['id']][] = $rx;
            $embedded[$id] = true;
        }
    }
    return ['by_exam'=>$byExam,'embedded'=>$embedded];
}

function dentalPrescriptionSheet(array $record, array $patient, bool $embedded = false, bool $showPatientDetails = true): string
{
    $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $meta = is_array($record['meta'] ?? null) ? $record['meta'] : (json_decode((string)($record['meta_json'] ?? ''), true) ?: []);
    $value = static fn($key) => trim((string)($meta[$key] ?? '')) ?: 'Not recorded';
    $medicine = trim((string)($meta['medicine_name'] ?? $record['title'] ?? '')) ?: 'Not recorded';
    ob_start();
    ?>
    <article class="dental-prescription-sheet<?= $embedded ? ' dental-prescription-embedded' : '' ?>">
        <div class="dental-sheet-details">
            <?php if (!$embedded): ?><p class="dental-sheet-label">RHU Dental Clinic</p><?php endif; ?>
            <div class="dental-sheet-heading">
                <h3>Prescription</h3>
                <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-800"><?= $escape($record['status'] ?? 'Issued') ?></span>
            </div>
            <?php if ($embedded || !$showPatientDetails): ?>
                <p class="dental-rx-record-info"><?= $escape($record['record_date'] ?? 'Not recorded') ?> / <?= $escape(trim((string)($record['provider_name'] ?? '')) ?: 'Dentist not recorded') ?></p>
            <?php else: ?><dl class="dental-sheet-patient">
                <div><dt>Patient ID</dt><dd>#<?= (int)($patient['id'] ?? $record['resident_id'] ?? 0) ?></dd></div>
                <div><dt>Name</dt><dd><?= $escape($patient['name'] ?? 'Not recorded') ?></dd></div>
                <div><dt>Address</dt><dd><?= $escape(trim((string)($patient['address'] ?? '')) ?: 'Not recorded') ?></dd></div>
                <div><dt>Date</dt><dd><?= $escape($record['record_date'] ?? 'Not recorded') ?></dd></div>
                <div><dt>Dentist</dt><dd><?= $escape(trim((string)($record['provider_name'] ?? '')) ?: 'Not recorded') ?></dd></div>
            </dl><?php endif; ?>
            <div class="dental-sheet-section dental-rx-medicine">
                <span class="dental-rx-symbol" aria-hidden="true">Rx</span>
                <div><h4>Medicine</h4><p class="dental-rx-name"><?= $escape($medicine) ?></p></div>
            </div>
            <dl class="dental-rx-directions">
                <?php foreach (['dosage'=>'Dosage','frequency'=>'How often','duration'=>'How long','quantity'=>'Quantity'] as $key=>$label): ?>
                    <div><dt><?= $escape($label) ?></dt><dd><?= $escape($value($key)) ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <div class="dental-sheet-section"><h4>Instructions</h4><p><?= $escape(trim((string)($record['notes'] ?? '')) ?: 'Not recorded') ?></p></div>
            <?php if ((int)($meta['inventory_id'] ?? 0) > 0): ?>
                <div class="dental-sheet-section"><h4>Dispensing details</h4>
                    <dl class="dental-rx-directions">
                        <div><dt>Stock item</dt><dd><?= $escape(trim((string)($meta['inventory_item_name'] ?? '')) ?: 'Inventory item #' . (int)$meta['inventory_id']) ?></dd></div>
                        <div><dt>Quantity issued</dt><dd><?= $escape(isset($meta['inventory_deducted_quantity']) ? $meta['inventory_deducted_quantity'] . ' ' . ($meta['inventory_unit'] ?? '') : 'Not recorded') ?></dd></div>
                        <div><dt>Location</dt><dd><?= $escape($value('dispense_location')) ?></dd></div>
                        <div><dt>Dispensed on</dt><dd><?= $escape(str_replace('T', ' ', $value('dispensed_at'))) ?></dd></div>
                    </dl>
                </div>
            <?php endif; ?>
            <p class="dental-sheet-footer">RHU Dental Clinic / Prescription record #<?= (int)($record['id'] ?? 0) ?></p>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

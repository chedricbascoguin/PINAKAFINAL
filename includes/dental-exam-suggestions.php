<?php
function dentalGumOptions(): array
{
    return ['Not assessed', 'Normal', 'Redness / inflammation', 'Swollen gums', 'Bleeding gums', 'Gum recession', 'Suspected periodontal concern'];
}

function dentalValidateExamApproval(array $post, int $staffId): array
{
    if ($staffId <= 0 || ($post['exam_approved'] ?? '') !== '1') throw new InvalidArgumentException('Review the findings and recommendation and confirm approval before saving.');
    return ['source'=>($post['recommendation_mode'] ?? '') === 'automatic' ? 'automatic_recommendation' : 'manual',
        'approved_by'=>$staffId, 'approved_at'=>date(DATE_ATOM), 'version'=>'findings-rules-v4'];
}

// Validate optional treatments before any part of a combined visit is saved.
function dentalValidateCareTreatments(array $post): array
{
    $teeth = static function (string $text, bool $allowAll = false): string {
        if ($allowAll && strtoupper(trim($text)) === 'ALL') return 'ALL';
        $numbers = preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!$numbers) throw new InvalidArgumentException('Enter the treated tooth numbers.');
        foreach ($numbers as $number) {
            if (!preg_match('/^[1-4][1-8]$/', $number)) throw new InvalidArgumentException('Use valid permanent FDI tooth numbers, such as 36, 37.');
        }
        return implode(', ', array_unique($numbers));
    };
    $rows = [];
    if (($post['care_procedure'] ?? '') === '1') {
        $type = trim((string)($post['care_procedure_type'] ?? ''));
        if (!in_array($type, ['Oral Prophylaxis','Dental Filling','Temporary Filling','Permanent Filling','Topical Fluoride','Other'], true)) throw new InvalidArgumentException('Select a valid dental procedure.');
        $rows[] = ['record_type'=>'procedure','title'=>$type,'tooth_numbers'=>$teeth((string)($post['care_procedure_teeth'] ?? ''), true),'notes'=>trim((string)($post['care_procedure_notes'] ?? '')),'meta'=>['procedure_type'=>$type,'materials'=>trim((string)($post['care_materials'] ?? ''))]];
    }
    if (($post['care_extraction'] ?? '') === '1') {
        $reason = trim((string)($post['care_extraction_reason'] ?? ''));
        if ($reason === '') throw new InvalidArgumentException('Enter the extraction reason.');
        $rows[] = ['record_type'=>'extraction','title'=>'Tooth extraction','tooth_numbers'=>$teeth((string)($post['care_extraction_teeth'] ?? '')),'findings'=>$reason,'notes'=>trim((string)($post['care_extraction_notes'] ?? '')),'meta'=>[]];
    }
    return $rows;
}

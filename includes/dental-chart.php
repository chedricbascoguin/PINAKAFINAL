<?php
function dentalRecordViewData(array $record): array
{
    $findings = (string)($record['findings'] ?? '');
    $meta = is_array($record['meta'] ?? null) ? $record['meta'] : (json_decode((string)($record['meta_json'] ?? ''), true) ?: []);
    $hasChart = is_array($meta['odontogram'] ?? null);
    $marks = $hasChart ? $meta['odontogram'] : [];
    if (preg_match('/\[Odontogram\]\s*(\{[^\r\n]*\})/', $findings, $match)) {
        if (!$hasChart) { $marks = json_decode($match[1], true) ?: []; $hasChart = true; }
        $findings = str_replace($match[0], '', $findings);
    }
    $findings = trim(str_replace(['[Tooth findings]', '[/Tooth findings]'], '', $findings));
    $normalized = [];
    foreach ($marks as $tooth => $conditions) {
        if (!preg_match('/^[1-4][1-8]$/', (string)$tooth)) continue;
        $conditions = is_array($conditions) ? $conditions : [$conditions];
        $valid = array_values(array_intersect($conditions, ['caries','filling','missing','to_extract','crown']));
        if ($valid) $normalized[$tooth] = [$valid[0]];
    }
    $cariesTeeth = array_keys(array_filter($normalized, static fn($conditions) => $conditions[0] === 'caries'));
    sort($cariesTeeth, SORT_NUMERIC);
    $warnings = [];
    $count = $hasChart ? count($cariesTeeth) : ($meta['caries_count'] ?? null);
    if ($hasChart && isset($meta['caries_count']) && (int)$meta['caries_count'] !== $count) {
        $warnings[] = 'Saved caries count (' . (int)$meta['caries_count'] . ') differs from chart (' . $count . '). Dentist review required.';
    }
    // Recognize both legacy "Caries 14, 15" and generated "Teeth #14, #15: Caries".
    $reported = [];
    preg_match_all('/(?:\bcaries\s*:?\s*((?:#?[1-4][1-8]\s*[, ]*?)+)(?=\s*(?:[.\r\n]|$))|\bteeth?\s+((?:#?[1-4][1-8]\s*[, ]*)+)\s*:\s*caries)/i', $findings, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        preg_match_all('/[1-4][1-8]/', ($match[1] ?? '') . ' ' . ($match[2] ?? ''), $numbers);
        $reported = array_merge($reported, array_map('intval', $numbers[0]));
    }
    $reported = array_values(array_unique($reported)); sort($reported, SORT_NUMERIC);
    if ($hasChart && $reported && $reported !== array_map('intval', $cariesTeeth)) {
        $warnings[] = 'Recorded remarks and chart identify different caries teeth. Dentist review required; original remarks retained.';
    }
    $summary = [];
    foreach (['caries'=>'Caries / decay','filling'=>'Filled / Pasta','missing'=>'Extracted / missing','to_extract'=>'For extraction assessment','crown'=>'Crown / prosthesis'] as $code => $label) {
        $teeth = array_keys(array_filter($normalized, static fn($conditions) => $conditions[0] === $code));
        sort($teeth, SORT_NUMERIC);
        if ($teeth) $summary[] = $label . ': ' . implode(', ', array_map(static fn($tooth) => '#' . $tooth, $teeth));
    }
    return ['findings'=>$findings, 'marks'=>$normalized, 'caries_count'=>$count, 'chart_summary'=>implode("\n", $summary), 'warnings'=>$warnings];
}

// Older snapshots may omit teeth; absence alone must not erase a recorded condition.
function dentalCumulativeChart(array $records): array
{
    usort($records, static fn($a, $b) => strcmp((string)($a['record_date'] ?? ''), (string)($b['record_date'] ?? '')) ?: ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0)));
    $marks = []; $sources = []; $warnings = []; $date = null;
    foreach ($records as $record) {
        $view = dentalRecordViewData($record);
        $meta = is_array($record['meta'] ?? null) ? $record['meta'] : (json_decode((string)($record['meta_json'] ?? ''), true) ?: []);
        $date = $record['record_date'] ?? $date;
        foreach ($view['marks'] as $tooth => $conditions) {
            $marks[$tooth] = $conditions;
            $sources[$tooth] = $date;
        }
        foreach ((array)($meta['odontogram_cleared'] ?? []) as $tooth) {
            if (is_scalar($tooth)) { unset($marks[$tooth], $sources[$tooth]); }
        }
        $warnings = array_merge($warnings, $view['warnings']);
    }
    return ['marks'=>$marks, 'sources'=>$sources, 'warnings'=>array_values(array_unique($warnings)), 'record_date'=>$date, 'record_count'=>count($records)];
}

// Occlusal crown silhouettes by permanent FDI tooth position.
// The outer (lip/cheek) edge points up; lower crowns are rotated outward.
function dentalToothGeometry(int $tooth): array
{
    $position = $tooth % 10;
    $upper = $tooth < 30;
    if ($position <= 2) {
        $width = $upper ? ($position === 1 ? 12 : 10) : ($position === 1 ? 8 : 9);
        return [
            'outline' => "M-$width,-10 Q0,-12 $width,-10 L" . ($width - 1) . ",2 Q7,10 0,12 Q-7,10 " . (1 - $width) . ",2 Z",
            'detail' => "M" . (3 - $width) . ",-6 Q0,-8 " . ($width - 3) . ",-6 M-5,3 Q0,8 5,3",
        ];
    }
    if ($position === 3) {
        return [
            'outline' => 'M0,-15 Q5,-13 10,-6 Q14,1 9,8 Q5,13 0,14 Q-5,13 -9,8 Q-14,1 -10,-6 Q-5,-13 0,-15 Z',
            'detail' => 'M0,-10 L0,5 M-7,1 Q0,4 7,1 M-5,7 Q0,10 5,7',
        ];
    }
    if ($position <= 5) {
        return [
            'outline' => 'M-9,-13 Q0,-17 9,-13 Q14,-8 12,1 Q13,10 6,14 Q0,17 -7,13 Q-14,9 -12,0 Q-14,-8 -9,-13 Z',
            'detail' => 'M-8,-6 Q0,-1 8,-6 M-8,7 Q0,2 8,7 M0,-9 Q-3,0 0,10',
        ];
    }
    return [
        'outline' => $upper
            ? 'M-12,-13 Q-4,-18 3,-14 Q12,-17 15,-8 L13,5 Q12,15 4,15 Q-3,18 -12,12 Q-17,5 -15,-3 Q-18,-9 -12,-13 Z'
            : 'M-12,-14 Q-5,-17 1,-14 Q9,-17 14,-10 Q18,-4 14,2 Q17,10 9,14 Q2,17 -4,14 Q-13,16 -16,8 L-16,-5 Q-17,-11 -12,-14 Z',
        'detail' => $upper
            ? 'M-10,-7 Q-2,-2 10,-7 M-9,8 Q0,2 9,8 M-1,-11 Q-4,0 1,11 M1,0 L8,3'
            : 'M-11,-6 Q0,0 11,-6 M-11,7 Q0,1 11,7 M0,-11 L-1,1 L2,11 M-8,12 L-6,5',
    ];
}

function dentalToothPlacement(string $arch, int $index): array
{
    $angle = pi() * $index / 15;
    return [
        220 - 112 * cos($angle),
        $arch === 'upper' ? 218 - 158 * sin($angle) : 302 + 140 * sin($angle),
        220 - 146 * cos($angle),
        $arch === 'upper' ? 218 - 188 * sin($angle) : 302 + 170 * sin($angle),
        ($arch === 'upper' ? 1 : -1) * (90 - $index * 12) + ($arch === 'lower' ? 180 : 0),
    ];
}

function dentalRecordChart(array $marks): string
{
    $colors = ['caries' => '#ef4444', 'filling' => '#3b82f6', 'missing' => '#94a3b8', 'to_extract' => '#f59e0b', 'crown' => '#8b5cf6'];
    $labels = ['caries' => 'Caries / decay', 'filling' => 'Filled / Pasta', 'missing' => 'Extracted / missing', 'to_extract' => 'For extraction', 'crown' => 'Crown / prosthesis'];
    $svg = '<svg viewBox="0 0 440 510" role="img" aria-label="Recorded permanent teeth, FDI numbering">';
    $svg .= '<text x="12" y="35" class="record-quadrant">UPPER RIGHT</text><text x="428" y="35" text-anchor="end" class="record-quadrant">UPPER LEFT</text>';
    $svg .= '<text x="12" y="488" class="record-quadrant">LOWER RIGHT</text><text x="428" y="488" text-anchor="end" class="record-quadrant">LOWER LEFT</text>';
    $svg .= '<path d="M62 259 H378" fill="none" stroke="#c47b5e" stroke-width="1"/><rect x="157" y="246" width="126" height="26" fill="#fffcf7"/><text x="220" y="264" text-anchor="middle" class="record-quadrant">ODONTOGRAM / FDI</text>';
    foreach (['upper' => [18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28], 'lower' => [48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38]] as $arch => $teeth) {
        foreach ($teeth as $i => $tooth) {
            [$x, $y, $lx, $ly, $rotation] = dentalToothPlacement($arch, $i);
            $geometry = dentalToothGeometry((int) $tooth);
            $conditions = $marks[$tooth] ?? [];
            $conditions = is_array($conditions) ? $conditions : [$conditions];
            $code = $conditions[0] ?? 'sound';
            $fill = $colors[$code] ?? '#fffcf7';
            $svg .= '<g><title>' . htmlspecialchars('Tooth #' . $tooth . ': ' . ($labels[$code] ?? 'No condition recorded'), ENT_QUOTES, 'UTF-8') . '</title>';
            $svg .= '<g transform="translate(' . $x . ' ' . $y . ') rotate(' . $rotation . ')"><path fill="' . $fill . '" stroke="#b76c4e" stroke-width="1.6" d="' . $geometry['outline'] . '"/><path fill="none" stroke="#945239" stroke-width="1" d="' . $geometry['detail'] . '"/></g>';
            if ($code === 'missing') {
                $markY = $y;
                $svg .= '<path class="record-extracted-mark" transform="translate(' . $x . ' ' . $markY . ')" d="M-11,-11 L11,11 M11,-11 L-11,11" fill="none" stroke="#7f1d1d" stroke-width="3.5" stroke-linecap="round" pointer-events="none"/>';
            }
            $svg .= '<text x="' . $lx . '" y="' . $ly . '" text-anchor="middle" dominant-baseline="middle" class="record-tooth-number">' . $tooth . '</text></g>';
        }
    }
    return $svg . '</svg>';
}


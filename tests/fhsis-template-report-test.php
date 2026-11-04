<?php
require dirname(__DIR__) . '/includes/fhsis-template-report.php';
function checkFhsisTemplate(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
foreach ([['2026-10-01', '2026-10-07', 0], ['2026-10-01', '2026-10-08', 1], ['2026-09-01', '2026-10-01', 2], ['2025-10-01', '2026-10-01', 3], ['2021-10-01', '2026-10-01', 4], ['1956-10-01', '2026-10-01', 17], [null, '2026-10-01', null], ['2026-02-30', '2026-10-01', null], ['2027-01-01', '2026-10-01', null]] as [$birth, $date, $expected]) {
    checkFhsisTemplate(fhsisMorbidityAgeBand($birth, $date) === $expected, 'Age boundary failed');
}
$cases = [];
for ($i = 0; $i < 16; $i++) $cases[] = ['disease' => 'Disease & ' . $i, 'icd' => 'A' . $i, 'birthdate' => '2000-01-01', 'sex' => 'Female', 'date' => '2026-10-01'];
$cases[] = $cases[0];
$cases[] = ['disease' => 'Disease & 0', 'icd' => 'A0', 'birthdate' => null, 'sex' => null, 'date' => '2026-10-01'];
$groups = fhsisMorbidityGroups($cases);
checkFhsisTemplate($groups[0]['total'] === 3 && $groups[0]['female'] === 2 && $groups[0]['unclassified'] === 1, 'Case reconciliation failed');
$ncdRow = ['resident_id' => 1, 'consultation_date' => '2026-10-05', 'report_birthdate' => '1990-01-01', 'report_sex' => 'Female', 'consultation_status' => 'Completed', 'diagnosis' => 'Essential hypertension', 'icd_code' => 'I10'];
$ncd = fhsisNcdIndicatorCells([$ncdRow, $ncdRow,
    array_merge($ncdRow, ['resident_id' => 2, 'consultation_status' => 'Scheduled']),
    array_merge($ncdRow, ['resident_id' => 3, 'diagnosis' => 'Possible diabetes', 'icd_code' => '']),
    array_merge($ncdRow, ['resident_id' => 4, 'icd_code' => 'E11.9', 'diagnosis' => 'Type 2 diabetes mellitus', 'report_birthdate' => '1950-01-01', 'report_sex' => 'Male']),
    array_merge($ncdRow, ['consultation_date' => '2026-09-30']),
], '2026-10-01');
checkFhsisTemplate($ncd['M18'] === 1 && $ncd['N18'] === 1 && $ncd['M17'] === 1, 'NCD deduplication or period grouping failed');
checkFhsisTemplate($ncd['AB24'] === 1 && $ncd['AD24'] === 1, 'Senior diabetes grouping failed');
checkFhsisTemplate(!isset($ncd['N19']), 'PhilPEN indicator incorrectly inferred');
$vaccination = ['resident_id' => 1, 'report_birthdate' => '2026-02-01', 'vaccination_date' => '2026-10-05', 'report_sex' => 'Female', 'vaccine_name' => 'OPV', 'dose_number' => '1'];
$vaccines = fhsisVaccineIndicatorCells([$vaccination, $vaccination,
    array_merge($vaccination, ['resident_id' => 2, 'report_birthdate' => '2025-11-01', 'report_sex' => 'Male']),
    array_merge($vaccination, ['resident_id' => 3, 'report_birthdate' => '2024-01-01']),
], 2026);
checkFhsisTemplate($vaccines['M14'] === 1 && $vaccines['N14'] === 1 && $vaccines['AB8'] === 1, 'Vaccine dose, deduplication, infant age or birth-year cohort failed');
$file = tempnam(sys_get_temp_dir(), 'fhsis-verify-');
$zip = $file . '.zip';
try {
    fhsisWriteTemplateReport(['month' => 'October', 'year' => 2026, 'scope' => 'All barangays (RHU aggregate)', 'facility' => 'Test RHU', 'municipality' => 'Nasugbu', 'province' => 'Batangas', 'cases' => $cases,
        'indicator_cells' => [5 => $ncd], 'source_records' => ['consultations' => [['resident_name' => 'Test Person', 'diagnosis' => '=text remains text']]]], $file);
    copy($file, $zip);
    $archive = new PharData($zip);
    $wb = simplexml_load_string($archive['xl/workbook.xml']->getContent());
    checkFhsisTemplate(count($wb->sheets->sheet) === 14, 'Morbidity overflow page, system records or notes missing');
    $ncdSheet = simplexml_load_string($archive['xl/worksheets/sheet5.xml']->getContent());
    $ncdSheet->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    checkFhsisTemplate((string)$ncdSheet->xpath('//s:c[@r="N18"]/s:v')[0] === '1', 'NCD not written to template');
    checkFhsisTemplate(str_contains($archive['xl/worksheets/sheet13.xml']->getContent(), 'Test Person'), 'System records not exported');
    foreach (new RecursiveIteratorIterator($archive) as $entry) {
        if (str_ends_with($entry->getFilename(), '.xml')) checkFhsisTemplate(simplexml_load_string($entry->getContent()) !== false, 'Invalid report XML');
    }
    $sheet = simplexml_load_string($archive['xl/worksheets/sheet11.xml']->getContent());
    $sheet->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    checkFhsisTemplate((string)$sheet->xpath('//s:c[@r="AU11"]/s:v')[0] === '3', 'Morbidity total incorrect');
    checkFhsisTemplate((string)$sheet->xpath('//s:c[@r="F1"]/s:is/s:t')[0] === 'FHSIS REPORT for the Month October   Year 2026', 'Period not set');
    checkFhsisTemplate(count($sheet->mergeCells->mergeCell) > 0 && (string)$sheet->pageSetup['orientation'] === 'landscape', 'Template layout lost');
    checkFhsisTemplate(count($sheet->xpath('//s:f')) === 0, 'Unexpected formula interpretation');
    checkFhsisTemplate(!str_contains($archive['xl/worksheets/sheet1.xml']->getContent(), 'resident_name'), 'Raw resident sheet unexpectedly exported');
    // Every registered report count displays a number even when the source cannot calculate it.
    foreach (fhsisIndicators() as $metric) {
        $doc = new DOMDocument(); $doc->loadXML($archive['xl/worksheets/sheet'.$metric['sheet'].'.xml']->getContent());
        $xp = new DOMXPath($doc); $xp->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($metric['columns'] as $column) {
            $value=$xp->evaluate('string(//s:c[@r="'.$column.$metric['row'].'"]/s:v)');
            checkFhsisTemplate($value!=='' && is_numeric($value), 'Blank report count remained: '.$metric['key'].' '.$column.$metric['row']);
        }
    }
    $fp = new DOMDocument(); $fp->loadXML($archive['xl/worksheets/sheet1.xml']->getContent());
    $xp = new DOMXPath($fp); $xp->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    checkFhsisTemplate($xp->evaluate('string(//s:c[@r="G14"]/s:v)')==='0' && $xp->evaluate('string(//s:c[@r="AD32"]/s:v)')==='0', 'FP opening/total cells not zero-filled');
    foreach (range(14,32) as $row) foreach (range(7,30) as $column) {
        $address=fhsisColumnName($column).$row;
        checkFhsisTemplate($xp->evaluate('string(//s:c[@r="'.$address.'"]/s:v)')==='0', 'FP acceptor count still blank: '.$address);
    }
    checkFhsisTemplate($xp->evaluate('string(//s:sheetView/@showZeros)')==='1', 'Excel can hide the zero values');
    checkFhsisTemplate(str_contains($archive['xl/worksheets/sheet14.xml']->getContent(), 'Display convention'), 'Default-zero explanation missing');
    unset($archive);
    echo "Passed: template export without ZipArchive, XML, selected period, age boundaries, missing demographics, totals, overflow pages and print layout.\n";
} finally { unset($archive); if (is_file($file)) unlink($file); if (is_file($zip)) unlink($zip); }

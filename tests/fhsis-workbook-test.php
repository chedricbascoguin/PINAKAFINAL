<?php
require dirname(__DIR__) . '/includes/fhsis_excel.php';
$file = tempnam(sys_get_temp_dir(), 'fhsis-test-');
try {
    $sheets = fhsisOrganizeResidentWorksheets([
        'OPD and Appointments' => [
            ['resident_name' => 'Ana & Cruz', 'consultation_date' => '2026-10-07', 'consultation_time' => '10:30', 'diagnosis' => '=1+1', 'id' => 123, 'created_by_staff_id' => 7],
            ['resident_name' => 'Ana & Cruz', 'consultation_date' => '2026-10-08', 'consultation_time' => '11:00', 'diagnosis' => 'Follow-up'],
        ],
        'Confidential Records' => [['resident_name' => 'Private Resident', 'result' => 'Private result']],
        'Report Summary' => [['records' => 2]],
    ]);
    if (array_key_first($sheets) !== 'Monthly Resident Records') throw new RuntimeException('Detailed records are not the first worksheet');
    if (count($sheets['Monthly Resident Records']) !== 2) throw new RuntimeException('Incorrect combined record count');
    if ($sheets['Monthly Resident Records'][0]['time'] !== '10:30') throw new RuntimeException('Consultation time missing');
    if ($sheets['Monthly Resident Records'][1]['diagnosis_or_case'] !== 'Follow-up') throw new RuntimeException('Visit details missing');
    if (str_contains(json_encode($sheets['Monthly Resident Records']), 'Private result')) throw new RuntimeException('Confidential record leaked into monthly records');
    foreach (['Monthly Resident Records', 'OPD and Appointments'] as $name) {
        if (array_key_exists('id', $sheets[$name][0]) || array_key_exists('created_by_staff_id', $sheets[$name][0])) throw new RuntimeException('Internal fields exported');
        if (count($sheets[$name][0]) !== 9) throw new RuntimeException('Unexpected columns exported');
    }
    fhsisWriteWorkbook($sheets, $file);
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new RuntimeException('Invalid workbook archive');
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (simplexml_load_string($zip->getFromIndex($i)) === false) throw new RuntimeException('Invalid workbook XML');
    }
    $sheet = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
    $sheet->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    if (count($sheet->xpath('//s:row')) !== 3) throw new RuntimeException('Repeated visits were lost');
    if (count($sheet->xpath('//s:f')) !== 0) throw new RuntimeException('Text interpreted as formula');
    if ((string)$sheet->sheetData->row[1]->c[0]->is->t !== 'Ana & Cruz') throw new RuntimeException('Resident name changed');
    $zip->close();
    echo "Workbook archive, XML, repeated visits, and text cells verified.\n";
} finally {
    unlink($file);
}

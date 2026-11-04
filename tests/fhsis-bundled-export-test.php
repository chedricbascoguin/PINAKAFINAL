<?php
$root=dirname(__DIR__);
$isolated=sys_get_temp_dir().DIRECTORY_SEPARATOR.'fhsis-bundled-'.bin2hex(random_bytes(12));
$files=['fhsis-template-report.php','fhsis-services.php','fhsis-quality.php','record-access.php','staff-record-departments.php','staff-access.php','assets/templates/fhsis-m1-m2.xlsx'];
if (!mkdir($isolated.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'templates',0700,true)) throw new RuntimeException('Cannot prepare isolated test.');
try {
    foreach ($files as $file) if (!copy($root.'/'.$file,$isolated.'/'.$file)) throw new RuntimeException('Cannot copy test dependency.');
    if (file_exists($isolated.'/fhsis-indicators.php') || file_exists($isolated.'/assets/fhsis-indicators.json')) throw new RuntimeException('External catalog unexpectedly present.');
    require $isolated.'/fhsis-template-report.php';
    if (count(fhsisIndicators())!==559) throw new RuntimeException('Bundled catalog incomplete.');
    $data=['month'=>'October','year'=>2026,'facility'=>'Test facility','municipality'=>'Test municipality','province'=>'Test province','scope'=>'Test scope','cases'=>[], 'submission_ready'=>false];
    $data['validation']=fhsisCoverageRows($data);
    fhsisWriteTemplateReport($data,$isolated.'/report.xlsx');
    $archive=new FhsisReportArchive($isolated.'/report.xlsx');
    if (!str_contains($archive->read('xl/workbook.xml'),'Indicator Review')) throw new RuntimeException('Bundled report incomplete.');
    $archive->close(); unset($archive);
    echo "Passed: actual M1/M2 export with BOTH external definition files absent (".(class_exists('ZipArchive')?'ZipArchive':'PharData').").\n";
} finally {
    unset($archive);
    foreach (array_merge($files,['report.xlsx']) as $file) if (is_file($isolated.'/'.$file)) unlink($isolated.'/'.$file);
    rmdir($isolated.'/assets/templates'); rmdir($isolated.'/assets'); rmdir($isolated);
}

<?php
require dirname(__DIR__) . '/includes/fhsis-template-report.php';
$seed = tempnam(sys_get_temp_dir(), 'fhsis-archive-');
$path = $seed . '.zip';
try {
    copy(dirname(__DIR__) . '/assets/templates/fhsis-m1-m2.xlsx', $path);
    $archive = new FhsisReportArchive($path);
    foreach (['xl/worksheets/sheet1.xml', 'new.xml'] as $entry) {
        $archive[$entry] = '<first/>';
        if (!isset($archive[$entry]) || $archive->read($entry) !== '<first/>') throw new RuntimeException('Pending archive entry cannot be read');
        $archive[$entry] = '<second/>';
        if ($archive->read($entry) !== '<second/>') throw new RuntimeException('Repeated replacement returned stale data');
    }
    unset($archive['new.xml']);
    if (isset($archive['new.xml'])) throw new RuntimeException('Deleted pending entry still exists');
    $archive->close();
    $archive = new FhsisReportArchive($path);
    if ($archive->read('xl/worksheets/sheet1.xml') !== '<second/>') throw new RuntimeException('Replacement was not saved');
    if (isset($archive['new.xml'])) throw new RuntimeException('Deleted entry was saved');
    $archive->close();
    echo 'Passed: archive replacement, pending reads, repeated writes, deletion and persisted contents (' . (class_exists('ZipArchive') ? 'ZipArchive' : 'PharData') . ").\n";
} finally {
    unset($archive);
    if (is_file($path)) unlink($path);
    if (is_file($seed)) unlink($seed);
}

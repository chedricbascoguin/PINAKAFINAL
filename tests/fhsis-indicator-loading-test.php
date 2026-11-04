<?php
require dirname(__DIR__).'/includes/fhsis-services.php';
$root=dirname(__DIR__);
$phpCatalog=require $root.'/includes/fhsis-indicators.php';
$jsonCatalog=json_decode(file_get_contents($root.'/assets/fhsis-indicators.json'),true,512,JSON_THROW_ON_ERROR);
if ($phpCatalog!==$jsonCatalog) throw new RuntimeException('PHP and JSON definitions differ.');
$missing=sys_get_temp_dir().'/fhsis-missing-'.bin2hex(random_bytes(10));
if (fhsisLoadIndicatorCatalog($root.'/includes/fhsis-indicators.php',$missing)!==$jsonCatalog) throw new RuntimeException('Missing JSON broke PHP catalog loading.');
if (fhsisLoadIndicatorCatalog($missing,$root.'/assets/fhsis-indicators.json')!==$jsonCatalog) throw new RuntimeException('JSON compatibility fallback failed.');
$bad=tempnam(sys_get_temp_dir(),'fhsis-bad-');
try {
    file_put_contents($bad,'<?php return null;');
    if (fhsisLoadIndicatorCatalog($bad,$root.'/assets/fhsis-indicators.json')!==$jsonCatalog) throw new RuntimeException('Invalid PHP catalog did not fall back.');
    if (fhsisLoadIndicatorCatalog($missing,$missing)!==$jsonCatalog) throw new RuntimeException('Missing external catalogs broke bundled fallback.');
    if (fhsisEmbeddedIndicatorCatalog()!==$jsonCatalog) throw new RuntimeException('Bundled definitions differ.');
    if (fhsisIndicators()!==$jsonCatalog['indicators']) throw new RuntimeException('Active reader does not use the complete bundled catalog.');
    if ($phpCatalog['template_sha256']!==hash_file('sha256',$root.'/assets/templates/fhsis-m1-m2.xlsx')) throw new RuntimeException('Catalog does not match template.');
    echo "Passed: PHP/JSON equality, missing JSON, missing PHP, invalid fallback, both external catalogs missing, active bundled reader and matching template.\n";
} finally { if (is_file($bad)) unlink($bad); }

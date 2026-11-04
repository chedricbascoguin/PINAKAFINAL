<?php
require dirname(__DIR__) . '/includes/fhsis-quality.php';
require dirname(__DIR__) . '/includes/family-planning-client-type.php';
function checkClientType($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$row = ['id'=>1,'resident_id'=>40,'consultation_date'=>'2026-10-01','chief_complaint'=>'Family Planning - New Acceptor: Limit pregnancies','diagnosis'=>'Family Planning Counseling','treatment'=>'Pills-POP | Supplies/medications: Pills','remarks'=>''];
$entry = fpClientHistoryEntry($row);
checkClientType($entry['method']==='Pills-POP' && $entry['resident_id']===40, 'Legacy supply visit must be available');
checkClientType(fpClientHistoryEntry($row+['consultation_status'=>'cancelled'])===null, 'Canceled record included');
checkClientType(fpClientHistoryEntry($row+['is_confidential'=>1])===null, 'Confidential record included');
checkClientType(fpClientHistoryEntry(array_replace($row,['diagnosis'=>'Pending OPD triage']))===null, 'Unserved appointment included');
checkClientType(fpClientHistoryEntry(array_replace($row,['chief_complaint'=>'Family Planning - Counseling Only: Advice']))===null, 'Counseling alone implies prior method use');
checkClientType(fpClientHistoryEntry(array_replace($row,['treatment'=>'Pills (subtype unknown)']))!==null, 'Unknown prior method erased history');
$native = ['id'=>2,'resident_id'=>40,'last_supply_date'=>'2026-10-02','contraceptive_method'=>'Condom','acceptor_type'=>'Continuing User','status'=>'Dropout'];
checkClientType(fpClientHistoryEntry($native,true)['dropout'], 'Explicit dropout status ignored');
checkClientType(!array_key_exists('remarks',$entry), 'Clinical notes exposed in form history');
echo "Family planning history checks passed: completed visits, confidentiality, cancellations, counseling, unknown methods, dropout, and minimal fields.\n";

<?php
require_once dirname(__DIR__) . '/includes/inventory-control.php';
require_once __DIR__ . '/appointment-expiry.php';

if (!function_exists('workflowDate')) {
    function workflowDate(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if (!$date || $date->format('Y-m-d') !== trim($value)) {
            throw new InvalidArgumentException('Enter a valid date.');
        }
        return $date->format('Y-m-d');
    }
}

if (!function_exists('workflowFields')) {
    function workflowFields(string $notes): array
    {
        $fields = [];
        foreach (preg_split('/\R+/', $notes) ?: [] as $line) {
            if (preg_match('/^\s*([^:]+):\s*(.*?)\s*$/', $line, $match)) {
                $fields[strtolower(trim($match[1]))] = trim($match[2]);
            }
        }
        return $fields;
    }
}

if (!function_exists('workflowConsultationIdentity')) {
    function workflowConsultationIdentity(array $post, string $idField = 'consultation_id'): array
    {
        $id = (int)($post[$idField] ?? 0);
        if ($id > 0) {
            return ['where' => 'id = :consultation_id', 'params' => ['consultation_id' => $id]];
        }
        $residentId = (int)($post['resident_id'] ?? 0);
        $date = substr(trim((string)($post['appointment_date'] ?? $post['consultation_date'] ?? '')), 0, 10);
        $time = trim((string)($post['appointment_time'] ?? $post['consultation_time'] ?? ''));
        if ($residentId <= 0 || $date === '' || $time === '') {
            throw new InvalidArgumentException('The consultation identity is incomplete. Select the resident appointment again.');
        }
        return [
            'where' => 'resident_id = :resident_id AND DATE(consultation_date) = :consultation_date AND consultation_time = :consultation_time',
            'params' => ['resident_id' => $residentId, 'consultation_date' => $date, 'consultation_time' => $time],
        ];
    }
}

if (!function_exists('workflowMedtechLabGuardSql')) {
    function workflowMedtechLabGuardSql(): string
    {
        return "(LOWER(COALESCE(chief_complaint, '')) REGEXP 'laboratory|blood[ \t\r\n]+work|medical technologist|diagnostic|rdt|rapid diagnostic|lab test' OR LOWER(COALESCE(remarks, '')) LIKE '%lab test%') AND LOWER(COALESCE(chief_complaint, '')) NOT REGEXP 'dental|dentist|tooth|oral'";
    }
}

if (!function_exists('workflowMedtechOpenStatusGuardSql')) {
    function workflowMedtechOpenStatusGuardSql(PDO $pdo): string
    {
        if (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'consultation_status')) {
            return "AND COALESCE(consultation_status, '') <> 'Completed'";
        }
        return "AND (diagnosis IS NULL OR diagnosis = '' OR diagnosis = 'Pending OPD Triage')";
    }
}

if (!function_exists('workflowAdjustStock')) {
    function workflowAdjustStock(PDO $pdo, array $post, int $staffId): void
    {
        $id = (int)($post['supply_id'] ?? $post['medicine_id'] ?? 0);
        $amount = filter_var($post['amount'] ?? '', FILTER_VALIDATE_INT);
        $action = (string)($post['stock_action'] ?? '');
        if ($action !== 'use') inventoryRequireAdmin();
        if ($action !== 'use' || $amount === false || $amount < 1) throw new InvalidArgumentException('Record a positive quantity used. Restocking and corrections are managed by the admin.');
        inventoryChange($pdo, $id, -$amount, ['service'=>'Laboratory','location'=>$post['usage_location'] ?? '', 'purpose'=>$post['reason'] ?? '']);

    }
}

if (!function_exists('workflowReceiveReferral')) {
    function workflowReceiveReferral(PDO $pdo, array $post, int $staffId): void
    {
        $results = trim((string)($post['external_results'] ?? $post['results'] ?? ''));
        if ($results === '') {
            throw new InvalidArgumentException('Select a referral and enter the external results.');
        }
        $identity = workflowConsultationIdentity($post, 'referral_id');
        $statement = $pdo->prepare("UPDATE consultations SET diagnosis = 'Laboratory referral received', remarks = CONCAT(COALESCE(remarks, ''), :results), updated_at = NOW() WHERE {$identity['where']} AND " . workflowMedtechLabGuardSql());
        $statement->execute(array_merge($identity['params'], ['results' => "\nExternal results: {$results}"]));
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('Laboratory referral was not found.');
        }
        $residentStmt = $pdo->prepare("SELECT resident_id FROM consultations WHERE {$identity['where']} ORDER BY id DESC LIMIT 1");
        $residentStmt->execute($identity['params']);
        $residentId = (int)($residentStmt->fetchColumn() ?: 0);
        if (function_exists('portalBroadcastWorkflowUpdate')) {
            portalBroadcastWorkflowUpdate($pdo, 'External laboratory results were received and recorded by MedTech.', $residentId, 'ResidentDashboard.php?tab=records', ['RHU_STAFF', 'MEDTECH']);
        }
    }
}

if (!function_exists('workflowRecordTest')) {
    function workflowRecordTest(PDO $pdo, array $post, int $staffId): void
    {
        $results = trim((string)($post['results'] ?? ''));
        $testDate = workflowDate((string)($post['test_date'] ?? date('Y-m-d')));
        if ($results === '') {
            throw new InvalidArgumentException('Select a consultation and enter the test results.');
        }
        $identity = workflowConsultationIdentity($post);
        $note = "\nLAB TEST {$testDate}: " . trim((string)($post['test_name'] ?? 'Laboratory test')) . " - {$results}";
        $usageItems = $post['inventory_usage_item_id'] ?? [];
        $usageQuantities = $post['inventory_usage_quantity'] ?? [];
        $usageItems = is_array($usageItems) ? $usageItems : [];
        $usageQuantities = is_array($usageQuantities) ? $usageQuantities : [];
        $usage = [];
        foreach ($usageItems as $index => $rawItemId) {
            $itemId = (int)$rawItemId;
            $quantity = (int)($usageQuantities[$index] ?? 0);
            if ($itemId <= 0 && $quantity <= 0) {
                continue;
            }
            if ($itemId <= 0 || $quantity <= 0) {
                throw new InvalidArgumentException('Each selected material must have a positive quantity used.');
            }
            $usage[$itemId] = ($usage[$itemId] ?? 0) + $quantity;
        }

        inventoryEnsureHistory($pdo);
        $usageDateTime = str_replace('T', ' ', (string)($post['usage_datetime'] ?? ''));
        if (strlen($usageDateTime) === 16) $usageDateTime .= ':00';
        if ($usage && ($usageDateTime === '' || trim((string)($post['usage_location'] ?? '')) === '')) throw new InvalidArgumentException('Record where and when the test materials were used.');
        $pdo->beginTransaction();
        try {
            $patientStmt = $pdo->prepare("SELECT * FROM consultations WHERE {$identity['where']} ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $patientStmt->execute($identity['params']);
            $appointment = $patientStmt->fetch(PDO::FETCH_ASSOC);
            if (!$appointment) throw new RuntimeException('Consultation was not found.');
            if (rhuAppointmentExpired($appointment)) throw new RuntimeException('This appointment has expired. Select a current appointment.');
            if (in_array(strtolower(trim((string)($appointment['consultation_status'] ?? ''))), ['completed', 'complete', 'cancelled', 'canceled', 'no-show', 'no show', 'rejected', 'referred'], true)) {
                throw new RuntimeException('This appointment is no longer open for a laboratory test.');
            }
            $usageResidentId = (int)$appointment['resident_id'];
            $usageLines = [];
            foreach ($usage as $itemId => $quantity) {
                $item = inventoryChange($pdo, $itemId, -$quantity, [
                    'service'=>'Laboratory','location'=>$post['usage_location'] ?? '',
                    'purpose'=>'Laboratory test materials', 'resident_id'=>$usageResidentId, 'used_at'=>$usageDateTime]);
                $usageLines[] = $item['item_name'] . ': ' . $quantity . ' ' . trim((string)($item['unit'] ?? 'unit(s)'));
            }

            if ($usageLines) {
                $note .= "\nMaterials used: " . implode('; ', $usageLines);
            }

            $statusSetSql = (function_exists('rhuColumnExists') && rhuColumnExists($pdo, 'consultations', 'consultation_status')) ? ", consultation_status = 'Completed'" : '';
            $statement = $pdo->prepare("UPDATE consultations SET remarks = CONCAT(COALESCE(remarks, ''), :note), diagnosis = :diagnosis{$statusSetSql}, updated_at = NOW() WHERE {$identity['where']} AND " . workflowMedtechLabGuardSql() . ' ' . workflowMedtechOpenStatusGuardSql($pdo));
            $statement->execute(array_merge($identity['params'], ['note' => $note, 'diagnosis' => trim((string)($post['test_status'] ?? 'Normal'))]));
            if ($statement->rowCount() < 1) {
                throw new RuntimeException('Consultation was not found.');
            }
            $audit = $pdo->prepare("INSERT INTO audit_logs (action, module_name, record_id, description, created_at) VALUES ('Laboratory Test Recorded', 'MedTech', :id, :description, NOW())");
            $audit->execute(['id' => (int)($post['consultation_id'] ?? 0), 'description' => $note]);
            $residentStmt = $pdo->prepare("SELECT resident_id FROM consultations WHERE {$identity['where']} ORDER BY id DESC LIMIT 1");
            $residentStmt->execute($identity['params']);
            $residentId = (int)($residentStmt->fetchColumn() ?: 0);
            if ($residentId <= 0 || !function_exists('portalSaveHealthRecordEntry')) {
                throw new RuntimeException('The resident health history could not be saved.');
            }
            $healthRecordId = portalSaveHealthRecordEntry($pdo, $residentId, [
                'record_type' => 'Laboratory test result',
                'diagnosis' => trim((string)($post['test_name'] ?? 'Laboratory test')) . ' - ' . trim((string)($post['test_status'] ?? 'Normal')),
                'notes' => trim("Results: {$results}" . ($usageLines ? "\nMaterials used: " . implode('; ', $usageLines) : '')),
                'status' => 'Completed',
                'date' => $testDate,
                'recorded_by_id' => $staffId ?: null,
            ]);
            if (!$healthRecordId) throw new RuntimeException('The resident health history could not be saved. No test or stock changes were saved.');
            if (!empty($post['fhsis_inline'])) {
                require_once dirname(__DIR__) . '/includes/fhsis-services.php';
                $source = $pdo->prepare("SELECT id, resident_id FROM consultations WHERE {$identity['where']} ORDER BY id DESC LIMIT 1");
                $source->execute($identity['params']);
                $clinicalSource = $source->fetch(PDO::FETCH_ASSOC);
                if (!$clinicalSource) throw new RuntimeException('The completed laboratory source could not be found.');
                fhsisSaveInlineServices($pdo, 'consultations', (int)$clinicalSource['id'], (int)$clinicalSource['resident_id'], $post, $_SESSION);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if (function_exists('portalBroadcastWorkflowUpdate')) {
            portalBroadcastWorkflowUpdate($pdo, 'A laboratory test result was recorded by MedTech.', $residentId, 'ResidentDashboard.php?tab=records', ['RHU_STAFF', 'MEDTECH']);
        }
    }
}

if (!function_exists('workflowCreateReferral')) {
    function workflowCreateReferral(PDO $pdo, array $post, int $staffId): void
    {
        $residentId = (int)($post['resident_id'] ?? 0);
        $testName = trim((string)($post['test_requested'] ?? $post['test_name'] ?? ''));
        $destination = trim((string)($post['destination'] ?? $post['facility_name'] ?? 'External laboratory'));
        $date = workflowDate((string)($post['referral_date'] ?? date('Y-m-d')));
        if ($residentId <= 0 || $testName === '') {
            throw new InvalidArgumentException('Select a resident and enter the requested laboratory test.');
        }
        $statement = $pdo->prepare("INSERT INTO consultations (resident_id, consultation_date, consultation_time, chief_complaint, diagnosis, remarks, created_at) VALUES (:resident_id, :date, CURTIME(), :complaint, 'Laboratory referral pending', :remarks, NOW())");
        $statement->execute([
            'resident_id' => $residentId,
            'date' => $date,
            'complaint' => 'Laboratory Referral: ' . $testName,
            'remarks' => "Destination: {$destination}",
        ]);
    }
}

if (!function_exists('workflowTestRows')) {
    function workflowTestRows(array $rows, array $auditRows): array
    {
        $auditByRecord = [];
        foreach ($auditRows as $audit) {
            $auditByRecord[(int)($audit['record_id'] ?? 0)] = (string)($audit['description'] ?? '');
        }
        $result = [];
        foreach ($rows as $row) {
            $notes = (string)($row['results'] ?? '');
            $auditNote = $auditByRecord[(int)($row['id'] ?? 0)] ?? '';
            $fields = workflowFields($notes . "\n" . $auditNote);
            $source = $auditNote !== '' ? $auditNote : $notes;
            $testName = (string)($row['test_name'] ?? 'Laboratory test');
            $resultText = trim((string)($fields['external results'] ?? ''));
            if (preg_match_all('/LAB TEST\s+(\d{4}-\d{2}-\d{2}):\s*(.*?)\s*-\s*([^\r\n]+)/i', $source, $matches, PREG_SET_ORDER) && $matches) {
                $latest = end($matches);
                $testName = trim((string)($latest[2] ?? $testName)) ?: $testName;
                $resultText = trim((string)($latest[3] ?? $resultText));
            }
            if ($resultText === '') {
                $resultText = trim((string)($row['diagnosis'] ?? $row['test_status'] ?? 'Completed'));
            }
            $status = strtolower(trim((string)($row['test_status'] ?? 'Completed')));
            if (!in_array($status, ['normal', 'abnormal', 'notable', 'pending', 'positive', 'negative', 'completed'], true)) {
                $status = 'completed';
            }
            $result[] = [
                $testName,
                $row['patient'] ?? 'Resident',
                (int)($row['age'] ?? 0),
                $row['barangay'] ?? 'Unassigned',
                $row['test_type'] ?? 'Laboratory',
                $row['ordered_by'] ?? 'RHU MedTech',
                $status,
                $resultText,
                $notes,
                $row['test_date'] ?? '',
                (int)($row['id'] ?? 0),
                'resident_id' => (int)($row['resident_id'] ?? 0),
            ];
        }
        return $result;
    }
}

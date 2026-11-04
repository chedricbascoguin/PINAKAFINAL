<?php
function dentalValidatePrescription(array $post): void
{
    $required = ['medicine_name'=>'Medicine name','dosage'=>'Dosage','frequency'=>'Frequency','duration'=>'Duration',
        'quantity'=>'Quantity','prescription_date'=>'Prescription date','instructions'=>'Instructions'];
    $mode = (string)($post['patient_mode'] ?? 'existing');
    if (!in_array($mode,['existing','walkin'],true)) throw new InvalidArgumentException('Select a valid patient type.');
    if ($mode === 'existing' && (int)($post['resident_id'] ?? $post['resident_id_hidden'] ?? 0) <= 0) throw new InvalidArgumentException('Select a registered patient.');
    if ($mode === 'walkin') $required += ['walkin_first_name'=>'First name','walkin_last_name'=>'Last name','walkin_birthdate'=>'Birthdate','walkin_sex'=>'Sex','walkin_contact'=>'Contact','walkin_barangay'=>'Barangay'];
    foreach ($required as $key=>$label) {
        if (trim((string)($post[$key] ?? '')) === '') throw new InvalidArgumentException($label . ' is required before issuing the prescription.');
    }
    foreach ($mode === 'walkin' ? ['prescription_date','walkin_birthdate'] : ['prescription_date'] as $key) {
        $value = (string)$post[$key];
        $date = DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!$date || $date->format('Y-m-d') !== $value || $value > date('Y-m-d')) throw new InvalidArgumentException('Enter a valid date that is not in the future.');
    }
    if (!preg_match('/^([1-9][0-9]*)\s*(?:[a-zA-Z][a-zA-Z .()\/-]*)?$/',trim((string)$post['quantity']))) throw new InvalidArgumentException('Enter a positive whole quantity, for example 21 caps.');
    if (trim((string)($post['inventory_id'] ?? '')) !== '') {
        $id = filter_var($post['inventory_id'],FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new InvalidArgumentException('Select a valid inventory item.');
        if (trim((string)($post['dispense_location'] ?? '')) === '') throw new InvalidArgumentException('Dispensing location is required for inventory items.');
        $value = str_replace('T',' ',(string)($post['dispensed_at'] ?? ''));
        if (strlen($value) === 16) $value .= ':00';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value);
        if (!$date || $date->format('Y-m-d H:i:s') !== $value || $date->getTimestamp() > time()+60) throw new InvalidArgumentException('Enter a valid dispensing date and time, not in the future.');
    }
}

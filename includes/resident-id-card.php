<?php
function residentIdCard(array $resident): void
{
    $escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $name = trim(implode(' ', array_filter([$resident['first_name'] ?? '', $resident['middle_name'] ?? '', $resident['last_name'] ?? ''])));
    $initials = mb_substr((string)($resident['first_name'] ?? ''), 0, 1) . mb_substr((string)($resident['last_name'] ?? ''), 0, 1);
    $dob = $resident['birthdate'] ?? $resident['date_of_birth'] ?? '';
    ?>
    <article class="resident-id" aria-label="Resident identification card">
      <div class="resident-id__top"><img src="assets/images/resihunity_logo.jpg" alt="ResiHUnity" class="resident-id__logo"><div><strong>ResiHUnity</strong><span>NASUGBU RURAL HEALTH UNIT I</span></div><span class="resident-id__type">RESIDENT<br>IDENTIFICATION</span></div>
      <div class="resident-id__body">
        <div class="resident-id__identity"><div class="resident-id__avatar" aria-hidden="true"><?= $escape($initials) ?></div><div class="resident-id__details"><span class="resident-id__label">RESIDENT NAME</span><h3><?= $escape($name) ?></h3><dl><div><dt>DATE OF BIRTH</dt><dd><?= $escape($dob ?: 'Not recorded') ?></dd></div><div><dt>SEX</dt><dd><?= $escape($resident['sex'] ?? $resident['gender'] ?? 'Not recorded') ?></dd></div></dl></div></div>
        <div class="resident-id__qr-area"><div class="resident-id__qr" data-resident-qr="<?= (int)($resident['id'] ?? 0) ?>" aria-label="Resident QR code"></div><span>SCAN FOR APPOINTMENTS</span></div>
      </div>
      <div class="resident-id__bottom"><div><span class="resident-id__label">PATIENT NUMBER</span><strong>Patient #<?= (int)($resident['id'] ?? 0) ?></strong></div><p>Present at your RHU visit.<br>For RHU services only.</p></div>
    </article>
    <?php
    // Keep QR rendering self-contained: some mobile browsers block the separate JS assets.
    $qrLibrary = dirname(__DIR__) . '/assets/vendor/qrcode.min.js';
    $qrInitializer = dirname(__DIR__) . '/assets/js/resident-id-card.js';
    if (is_file($qrLibrary) && is_file($qrInitializer)) {
        echo '<script>';
        readfile($qrLibrary);
        echo "\n";
        readfile($qrInitializer);
        echo '</script>';
    } else {
        echo '<script>document.querySelectorAll("[data-resident-qr]").forEach(function (element) { element.textContent = "QR unavailable. Please contact the RHU."; });</script>';
    }
    ?>
    <?php
}

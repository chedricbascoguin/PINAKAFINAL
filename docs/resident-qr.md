# Resident QR IDs and appointment scanning

Residents open **My Profile** to see their resident ID card, then select **View / Print ID** for a printable copy. `ResidentID.php` uses the signed-in resident ID only; it cannot select another resident through a query parameter. The card can be printed, saved as PDF, or captured in a screenshot.

Staff select **Scan Resident ID** on their dashboard to open a floating modal. Camera scanning and appointment results stay inside the modal; closing it releases the camera and returns focus to the dashboard. `StaffResidentAppointments.php` uses camera scanning only. Select **Start camera** and allow camera access, then point the camera at the resident ID. Phone cameras can also open the QR URL directly. Camera access needs HTTPS or localhost; phones must open the portal through a reachable address, rather than a desktop's localhost address, when generating their ID.

The code contains a portal URL and patient number, without medical details or credentials. The destination requires an authenticated staff session and the existing staff PIN lock. A scan before staff sign-in preserves the destination through `RHULogin.php`.

Appointments are the existing `consultations` records created by the resident booking form. Service categories determine the department, with assigned provider role as a fallback for legacy uncategorized bookings. Complaint text never overrides a known category. Dentist sees dental; medical technologist sees laboratory; midwife sees maternal care; nurse sees immunization and general health checkups; physician sees general medical; sanitary inspector sees inspections. Administrator and secretary see the pending administrative queue across departments. Unsupported roles are denied. Confidential records are excluded.

Pending includes scheduled, pending, confirmed, approved, waiting, and in-progress visits, including overdue visits that have not been closed. Completed, cancelled, referred, and no-show visits are excluded, including legacy status notes and completed diagnoses without a status. Filtering happens on the server before HTML is generated. Scanning never changes an appointment's status.

QR generation and image decoding use vendored [QRCode.js](https://github.com/davidshimjs/qrcodejs) (MIT) and [jsQR](https://github.com/cozmo/jsQR) (Apache 2.0). Licenses are in `assets/vendor`; patient identifiers and camera frames are not sent to third-party QR services.

Checks: `php tests/resident-qr-test.php`, `python tests/resident-qr-http-test.py`, `node tests/resident-qr-code-test.cjs`, and PHP syntax checks for changed PHP files. Real camera permission and scanning should also be checked on the deployment device.

Appointment cards are links to the signed-in staff member's workflow. In the floating scanner, selecting a card navigates the dashboard itself and releases the scanner camera. Links carry the resident and exact consultation ID; nursing immunization opens the vaccine form for that booking, nursing checkups open OPD, and other roles open their respective queue or test form. Existing authorization remains in each destination. Unavailable visits are reported instead of silently choosing another booking.

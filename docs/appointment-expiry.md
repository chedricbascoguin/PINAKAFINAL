# Appointment expiry

Unfinished appointments expire the day after their appointment date in Asia/Manila. Completed and cancelled records are preserved. Expired appointments are excluded from resident appointment lists, staff queues and QR selection. Records remain stored for history.

`scripts/appointment-expiry-job.php` is CLI-only. Run it every 15 minutes on the database server. It creates one count notification for each newly detected group of expired appointments per resident. The notification and per-appointment receipts commit together, preventing repeated notifications during polling or overlapping jobs. The dashboard also performs this check when opened.

On Windows, `scripts/install-appointment-expiry.ps1` installs the `ResiHUnity Appointment Expiry` task. By default it runs while the installing Windows account is signed in; `-RunAsSystem` installs a service-account task when permitted. An existing task is never overwritten. The server and MySQL must be running. For a hosted deployment, configure its own PHP CLI scheduler; the local Windows task does not deploy to hosting.

Check connectivity without creating notifications: `C:\xampp\php\php.exe scripts/appointment-expiry-job.php --check`.

The latest background run is reported in `scratch/appointment-expiry-status.json`. Failed runs log an error without storing resident names or test results there.

# Background follow-up reminders

`follow-up-reminder-job.php` runs from PHP CLI. It creates in-app reminders and delivers email and subscribed browser push reminders for consultation follow-up dates and pending dental referral follow-up dates. Each schedule receives a reminder on the day before and on the date, using Asia/Manila dates. Family reminders go to the linked account owner.

Windows setup: run `install-follow-up-reminders.ps1` with permission to register a SYSTEM scheduled task. It runs every 15 minutes, first starting 15 minutes after installation. An existing task is never overwritten. The server, MySQL and internet access must remain available; Apache is not required for the CLI runner. A sleeping or powered-off server cannot send reminders. For hosted deployments, schedule PHP CLI with cron every 15 minutes instead of registering this local Windows task.

Check without sending: `C:\xampp\php\php.exe follow-up-reminder-job.php --check`.

Email uses the existing system SMTP settings and the account owner's resident email. Push requires an active browser subscription and notification permission; device/browser policies can affect delivery. No SMS integration is included.

External successes are stored separately from portal notifications. Failures retry on the next run that day; an unavailable email address or push subscription is skipped. Deleted/read portal notifications do not reset the delivery history. A filesystem lock prevents overlapping local job runs. An interruption after the external provider accepts a message but before recording success may cause a retry and duplicate delivery. Do not run this job concurrently on multiple servers without a shared job lock.

The runner rechecks canceled or rescheduled records before sending. Consultation records marked completed may still have a future return date, so completion of the original consultation does not cancel the follow-up; clear/change the follow-up date when it is no longer required.

Verification performed: PHP syntax and read-only CLI database/configuration check. No live patient message was sent during verification. Task Scheduler exposes job exit codes; PHP error logs capture failures.

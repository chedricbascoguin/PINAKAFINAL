# Authenticated workflow tests

Run with MySQL started in XAMPP:

```powershell
cd C:\xampp\htdocs\htdocs\tooling
npm.cmd run test:workflows
```

This creates a new `rhu_pw_<timestamp>` database from the schema export, seeds
synthetic accounts, starts a PHP server bound to a random port on 127.0.0.1,
runs the browser tests, and drops that database afterwards. Existing application
records and `.env` are not copied or edited. PHP sessions are stored separately.
The harness expects XAMPP's local MySQL root account with an empty password and
`C:/xampp/php/php.exe`; set `PHP_BINARY` to override the PHP executable.

The test router pins the database connection and disables configuration fallback.
The harness captures email in the test session, including the administrator's real
generated MFA code, instead of contacting SMTP. It does not bypass MFA verification.
The schema export currently lacks the sanitary_records primary key; the fixture
adds that key only in its temporary database.

Covered workflows:

- Real resident, nurse, dental, midwife, medtech, sanitary, secretary and admin login.
- Staff first-login unlock PIN setup and dashboard section navigation.
- Admin credential verification and MFA using captured test email.
- Resident appointment booking, nurse assessment/completion, and the saved
  assessment appearing in both resident and admin views.
- Resident and staff logout, with protected access checked afterwards.
- New resident registration followed by successful login.
- Existing-patient account-link request, administrator approval and resident login.
- Administrator announcement publishing and its public opening popup.
- Administrator stock addition, nurse stock usage, persisted quantity checks,
  and CSV export contents.

The public suite remains a separate command: `npm.cmd run test:e2e`.
Workflow traces and screenshots go under `tooling/test-results/rhu_pw_*`;
HTML reports go under `tooling/playwright-workflows-report/rhu_pw_*`.
To inspect a report: `npx.cmd playwright show-report <report-folder>`.
To run a subset: `npm.cmd run test:workflows -- --grep 'inventory:'`.

Coverage limits: these are representative integrated workflows, not every form
or clinical procedure. Department navigation does not establish that every
dental treatment, laboratory result, prenatal record, vaccination, sanitary
inspection, certificate, report type, permission combination or validation edge
case works. External email delivery, maps, camera scanning, and production
hosting behavior are not established by this local suite.

Keep `tests`, `tooling`, `scratch`, and generated reports out of public_html.
If a run is forcibly terminated before cleanup, only its explicitly named
`rhu_pw_<timestamp>` database may remain; never delete your application database.

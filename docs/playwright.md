# Browser tests

Start Apache and MySQL in XAMPP. The default site address is
`http://localhost/htdocs/`.

From PowerShell:

```powershell
cd C:\xampp\htdocs\htdocs\tooling
npm.cmd ci
$env:PLAYWRIGHT_BROWSERS_PATH = 'C:\xampp\htdocs\htdocs\scratch\playwright-browsers'
npx.cmd playwright install chromium
npm.cmd run test:e2e
```

Use `npm.cmd run test:e2e:headed` to watch the browser, or
`npm.cmd run test:e2e:ui` for the interactive runner.
Use `npm.cmd run test:e2e:report` to open the HTML report after a run.
The `.cmd` commands work with PowerShell's default script execution restrictions.

Tests live in `tests/e2e`; configuration lives in `tooling/playwright.config.js`.
The 25 browser checks cover the public homepage, announcement dismissal,
resident/staff/administrator login forms, browser input validation, password
visibility, registration and recovery navigation, staff portal navigation,
mobile resident login, and signed-out access protection for ten restricted pages.
They do not submit credentials or change application records. The public-workflow
suite blocks non-GET/HEAD/OPTIONS browser requests as an additional safeguard.

This command covers public and signed-out pages. For authenticated workflows,
run `npm.cmd run test:workflows`; see [playwright-workflows.md](playwright-workflows.md)
for the isolated database setup, covered workflows and remaining coverage limits.

To test another installation:

```powershell
$env:PLAYWRIGHT_BASE_URL = 'http://localhost/htdocs/'
npm.cmd run test:e2e
```

Keep the trailing slash in the base URL.

Each runner uses a separate `tooling/test-results/run-*` directory to prevent
overlapping UI and command-line sessions from deleting each other's trace files.
After changing the configuration, close and restart any existing Playwright UI.

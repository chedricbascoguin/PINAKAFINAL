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
The smoke tests check the public homepage and navigation to resident sign-in.
They do not submit credentials or change records.

To test another installation:

```powershell
$env:PLAYWRIGHT_BASE_URL = 'http://localhost/htdocs/'
npm.cmd run test:e2e
```

Keep the trailing slash in the base URL.

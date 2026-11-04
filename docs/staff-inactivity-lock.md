# Staff and administrator inactivity lock

Staff and admin dashboards lock after 300 seconds without user activity. RHU_STAFF_IDLE_SECONDS in staff-session-lock.php controls the interval. Background polling does not extend it; tabs share the server session.

Each user creates a separate **4 to 6 digit unlock PIN**. The server requires setup before protected pages can be accessed when no PIN exists. Use **Unlock PIN** in the dashboard header to change it. Enter and confirm the PIN; leading zeros are preserved. The account login password is never accepted by the lock screen and is never used as a fallback. Previously created screen passwords are not used by the PIN flow.

PIN hashes are stored per staff account in staff_screen_pins, created on the first successful setup. Changing a PIN requires the current PIN, except immediately after a normal fresh account sign-in. A forgotten PIN can be reset by signing out, signing in normally (including existing admin MFA), then opening Unlock PIN before inactivity locking occurs. A locked session with no PIN must sign in again before creating one; PIN setup cannot bypass an expired lock.

**Lock now** immediately locks the session. Protected page/API requests are blocked by the shared database bootstrap; locked APIs and POST requests receive HTTP 423. Unlocking requires the PIN and rotates the session ID. Five incorrect attempts trigger a one-minute session cooldown. Mutating lock actions require a CSRF token. Protected responses use no-store caching. A device/browser that is suspended cannot execute the screen timer until it resumes, but server checks still protect requests.

Unlocking reloads the dashboard, so unsaved edits are not restored. CLI background reminder jobs are unaffected.

Verification: `php tests/staff-session-lock-test.php` and `python tests/staff-session-lock-http-test.py`. The HTTP test runs a temporary localhost router with synthetic sessions and a mock PDO; it verifies required setup, digit/length validation, leading-zero preservation, successful PIN unlock, incorrect PINs, cooldown, CSRF and blocked GET/POST requests without modifying real accounts.

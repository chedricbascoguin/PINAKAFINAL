# ResiHUnity

PHP and MySQL system for resident services and Rural Health Unit workflows.

## Local setup

1. Install XAMPP with PHP and MySQL, and place this project in the Apache document root.
2. Enable Apache mod_rewrite and allow the included .htaccess rules.
3. Import database/database_schema.sql. Its default database name is if0_42839743_rhu; change the CREATE DATABASE and USE statements if needed.
4. Copy .env.example to .env and configure the database name and credentials to match your MySQL installation.
5. Start Apache and MySQL and open the project URL.
6. Configure SMTP values to enable email delivery.

Application entry points are in the root and pages/. Shared helpers are in includes/, browser assets in assets/, and styles in styles/. Documentation is in docs/ and checks in tests/.

Optional Node tooling is in tooling/. Run npm commands with npm --prefix tooling. The reminder job is scripts/follow-up-reminder-job.php.

This source snapshot includes a schema without records. Local credentials, patient uploads, database data dumps, logs, archives, temporary files, and prior Git history are excluded.

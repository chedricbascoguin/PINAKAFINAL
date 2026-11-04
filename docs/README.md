<<<<<<< HEAD

  # RHU PROTOTYPE

  This is a code bundle for RHU PROTOTYPE. The original project is available at https://www.figma.com/design/86Jx8V7eJRJgp7eG7ffLyo/RHU-PROTOTYPE.

  ## Running the code

  Run `npm i` to install the dependencies.

  Run `npm run dev` to start the development server.
  
=======
# rhu_web
>>>>>>> eeb9ffb50e99992a6911892e0671d44fd7657f0b


## File organization

Only dashboards, `.env`, `index.php`, and `db.php` remain as application files in
the root. `.htaccess` and `.gitignore` stay there because Apache and Git use them
at that location.

- `pages/`: login, registration, landing, supporting pages, manifest endpoint, and routers.
- `includes/`: shared PHP helpers and supporting endpoints.
- `assets/images/`, `assets/js/`, `assets/pwa/`: images, browser scripts, service worker, and manifests.
- `styles/`, `database/`, `docs/`, `archives/`, `tests/`: stylesheets, SQL, documentation, archives, and checks.
- `scripts/`: reminder job, its task installer, and maintenance scripts.
- `tooling/`: package files, build configurations, and the example environment file.
- `logs/`: runtime logs.

Apache `.htaccess` preserves original page and PWA URLs through internal rewrites.
Serve using Apache with mod_rewrite enabled. Component routes resolve pages from
both the root and `pages/`. Run npm commands with `npm --prefix tooling`.
The reminder job now lives at `scripts/follow-up-reminder-job.php`; existing
scheduled tasks must point to that path. The installer is beside the job.
`docs/file-organization.json` records file moves.

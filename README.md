# FF Spotless

FF Spotless is a mobile-first Laravel checklist application. It runs with PHP 8.3, Laravel 13, Inertia, and MySQL 8.4.

## Local Docker setup

1. Copy the environment template and set strong, unique local values for `APP_KEY`, `DB_PASSWORD`, and `DB_ROOT_PASSWORD`.

   The development-only default admin password is `12345678`. Change
   `CHECKLIST_ADMIN_PASSWORD` in `.env` before sharing the environment,
   deploying, or allowing real users to access the application.

   Keep `DB_TIMEZONE=+00:00` so MySQL stores completion instants in UTC. The UI converts them to Kuala Lumpur time.

   ```powershell
   Copy-Item .env.example .env
   ```

   Generate an application key with Docker, then paste the displayed value into `APP_KEY` in `.env`:

   ```powershell
   docker compose run --rm --no-deps app php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
   ```

2. Build and start the app and database:

   ```powershell
   docker compose up --build -d
   ```

3. Apply migrations and verify routes:

   ```powershell
   docker compose exec app php artisan migrate --force
   docker compose exec app php artisan route:list
   ```

The application is available at [http://localhost:8108](http://localhost:8108). MySQL is intentionally bound only to `127.0.0.1:8097`; connect with the database credentials in `.env`.

Useful commands:

```powershell
docker compose logs -f app
docker compose exec app php artisan test
docker compose down
```

`docker compose down -v` also deletes the local MySQL volume and all local checklist data.

## Docker development with live reload

Use the development Compose file when you are editing the application. It mounts the source code into the app container and starts Vite for Vue and CSS hot reload; the main Compose file remains production-style.

Start it once (and after changes to `composer.lock` or the Docker configuration):

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml up --build -d
```

After that, changes to PHP, Vue, CSS, routes, and configuration files are available without rebuilding Docker. Vue and CSS changes reload automatically; refresh the browser for PHP changes.

If `package-lock.json` changes, restart Vite so it installs the updated JavaScript dependencies:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml restart vite
```

To return to the production-style Compose setup, stop the development stack, then start the main file again:

```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml down
docker compose -f docker-compose.yml up --build -d
```

## PWA behavior

The manifest and FF Spotless icons are served from `public/`. The Vite PWA build generates the service worker, which precaches only Vite build output and PWA assets. It deliberately never caches navigation, Inertia, authenticated, or POST responses, so checklist reads and writes always reach the server.

The Inertia application registers the Vite-generated service worker and includes the manifest/theme metadata in its root layout. This enables installation only over HTTPS (or `localhost` during development).

## Checklist operations

- Admins manage ordered sessions, daily task templates, weekly task templates,
  credit hours, history, evidence, and statistics from the admin dashboard.
- Weekly tasks are available from Monday, may be completed early, and roll
  forward through Sunday when incomplete or when a day is marked MC/not
  available.
- Every completion requires JPEG, PNG, or WebP evidence. Evidence is stored on
  Laravel's private `local` disk and is streamed only through an authenticated
  admin route. Do not create a public symlink to `storage/app/private`.
- The upload UI reflects PHP's `max_file_uploads`, `upload_max_filesize`, and
  `post_max_size`. Each individual image is additionally limited to 10 MB.
- Comparable missed-task and credit statistics begin on the date the feature
  migration is applied. Earlier completion history remains available.

## Hostinger deployment

Hostinger Git deployment pulls from the `production` branch. Commit and push
production-ready source changes to that branch before triggering a Hostinger
deploy.

Hostinger SSH on this hosting plan does not support `npm`. Build the frontend
on a developer machine from the same source commit that will be deployed:

```bash
npm install
npm run build
```

Because Hostinger deploys this project through Git, commit the complete
generated folder with the matching Laravel source:

```text
public/build/
```

If you ever deploy with File Manager instead of Git, place that complete folder
at:

```text
public_html/public/build/
```

Replace the previous `build` folder as a complete folder. Do not copy only
individual hashed files, because the Vite manifest, CSS, JavaScript chunks, and
service worker must all come from the same build.

Keep the Laravel application and secrets outside the web root:

```text
/home/<account>/ffspotless    # complete Laravel application
/home/<account>/private/.env  # permissions: owner read/write only
/home/<account>/public_html   # contents copied from ffspotless/public
```

`public/index.php` detects this layout only when its normal sibling `vendor/` directory is absent, then loads `/home/<account>/ffspotless`. `bootstrap/app.php` automatically uses the sibling `private/.env` when it exists. Do not put `.env`, `vendor/`, `app/`, or `storage/` under `public_html`.

### Build and publish a single release

Build the frontend from the exact application source that will be deployed. Do
not copy individual hashed files into an existing `public/build` directory: an
HTML page, Inertia asset version, and dynamic Dashboard chunk must all come
from the same build.

```bash
cd /home/<account>/ffspotless
npm ci
npm run build
```

Upload or activate the application release and the complete matching
`public/build` directory together. Only after the release is in place, copy the
matching contents of `ffspotless/public` to `public_html` in one deployment
step. Remove no-longer-referenced build files only after the new public files
are active. This prevents browsers from receiving a new page with an old
Dashboard chunk or service worker asset.

After uploading an updated application or changing `private/.env`, run from the application directory:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
```

Before applying the configurable-session migration, back up both the MySQL
database and `storage/app/private`. Ensure `storage/app/private/evidence` can be
written by PHP but cannot be served directly by Apache. Review the hosting
account's PHP upload limits before enabling evidence uploads. Evidence photo
watermarking requires the PHP `gd` extension with JPEG, PNG, and WebP support.
Phone-photo orientation correction for JPEG evidence also requires the PHP
`exif` extension.

Changing `CHECKLIST_ADMIN_PASSWORD` in `private/.env` takes effect after the config cache is rebuilt and PHP has reloaded. Never deploy the development default of `12345678`; replace it with a strong secret in `private/.env`. The password is never stored in the session; the configured session only records successful master-admin authentication.

For production, set `APP_ENV=production`, `APP_DEBUG=false`, a HTTPS `APP_URL`, `SESSION_SECURE_COOKIE=true`, and `DB_TIMEZONE=+00:00`. Keep `storage/` and `bootstrap/cache/` writable by PHP, and restrict `private/.env` to the hosting account owner.

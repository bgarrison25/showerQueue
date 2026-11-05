# Deploying ShowerQueue

## Host and environment

Deploy on a conventional PHP 8.3+ server (Laravel Forge, a VPS with Nginx/PHP-FPM, or an equivalent Laravel host). This application needs persistent disk for SQLite, a queue worker, and a scheduler. It cannot run as a static site or directly in Cloudflare Workers. A LAN-only hostname must still offer trusted HTTPS for phone PWA push.

Use one application instance initially. Keep the checkout and SQLite database on a local server filesystem, **outside OneDrive, Dropbox, SMB, or NFS synchronization**. SQLite WAL requires reliable local file locking. The current workspace is suitable for development; copy the deployment to its own local server directory. Only the `public/` directory is web-accessible. Never serve the repository root.

Install PHP extensions described in the README. Point Nginx to `/var/www/showerqueue/public`, direct requests through `try_files $uri $uri/ /index.php?$query_string`, and forward only `index.php` to PHP-FPM. Deny dotfiles other than ACME’s `.well-known` path. Use a trusted TLS certificate and redirect HTTP to HTTPS. Configure trusted proxies to match your host if TLS terminates upstream; do not blindly trust arbitrary proxy addresses.

Set server-owned environment values:

```dotenv
APP_NAME=ShowerQueue
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-private-hostname.example
APP_KEY= # generate once with php artisan key:generate
DB_CONNECTION=sqlite
DB_DATABASE=/var/lib/showerqueue/database.sqlite
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database
VAPID_SUBJECT=mailto:your-contact@example.com
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
```

Replace the hostname and contact. Keep `.env`, `APP_KEY`, and VAPID private key out of Git and logs. Back up the keys securely. Do not generate a new `APP_KEY` on routine updates. The environment sample has local-development defaults and must be adjusted before production.

Create the SQLite file and its directory before migration. Give the application user write access to the database **and its containing directory**, `storage/`, and `bootstrap/cache/`; restrict other users. Do not use world-writable permissions. SQLite’s WAL and SHM files live beside the database.

For a fresh deployment:

```sh
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build
php artisan key:generate
php artisan migrate --force
php artisan showerqueue:setup
php artisan webpush:vapid
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Run `key:generate` only if `APP_KEY` is absent. Run setup interactively in a private terminal so the six one-time passwords can be saved securely. If environment values are supplied through your host’s secret store rather than `.env`, place the VAPID command’s generated values there before config caching. The web push channel is the one additional runtime package besides Livewire; it handles VAPID and encrypted push delivery.

Visit `/login`, sign in as Bill or Aleasha, choose a private credential, and check Household settings. In particular, set an actual Friday when the children are home; the initial reference (`2026-11-06`) is a configurable starting value, not a claim about custody arrangements. Check timezone, scheduling hours, slot duration, and absence restriction. Enable phone push individually in Preferences.

## MySQL

Use MySQL 8.0.16+ with InnoDB and UTC server/session timezone. Configure:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=showerqueue
DB_USERNAME=your_app_user
DB_PASSWORD= # supply privately
```

Remove the SQLite `DB_DATABASE` path before replacing it with the MySQL database name. Create a dedicated database and account with the schema privileges required for migrations. Use a separate migration role if your operational setup supports it. Rebuild config after changes. The migration uses portable generated daily keys with driver-specific concatenation and unique UTC minute claims. All application writes lock the singleton settings row in InnoDB.

Changing `DB_CONNECTION` does **not** copy existing data. For an occupied household, schedule maintenance, stop queue/scheduler writes, make a verified backup, transfer all tables including reservations, minute claims, swaps, recurrences/results, sessions, notifications/delivery ledger, push subscriptions, settings, and users, preserving IDs and UTC timestamps. Verify counts and constraints, test on a copy, then switch the environment and clear/rebuild config. Do not rerun account setup on the destination after importing users.

## Queue and scheduler

Run the scheduler every minute as the application user:

```cron
* * * * * cd /var/www/showerqueue && /usr/bin/php artisan schedule:run >> /var/log/showerqueue-scheduler.log 2>&1
```

The scheduler processes timer completion, swap expiry, and reminders each minute. It materializes recurring bookings daily at 00:05 UTC, covering the next 30 household days. Jobs use `withoutOverlapping`; the database lock also protects concurrent manual runs. Keep a worker running under Supervisor or systemd:

```ini
[program:showerqueue-worker]
command=/usr/bin/php /var/www/showerqueue/artisan queue:work database --sleep=1 --tries=3 --timeout=45 --max-time=3600
directory=/var/www/showerqueue
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=/var/log/showerqueue-worker.log
```

Adjust the PHP path, app path, application user, and log permissions. The default database queue retry interval is 90 seconds, longer than the worker timeout. Push requests have short network timeouts and three retries. Monitor `php artisan queue:failed`, worker logs, the scheduler log, disk capacity, and `/up`. Retry a failed job only after fixing its cause. The inbox persists even when push fails. Retain logs according to household preference; they must never contain credentials.

For local Windows development, `composer run dev` runs `schedule:work` and the worker. For an always-on Windows host, run PHP workers as managed services and schedule `php artisan schedule:run` every minute using Task Scheduler. Do not expose the PHP development server to the Internet.

## Backups and updates

Back up the database daily and before updates. With SQLite, use a consistent online backup (`sqlite3 /var/lib/showerqueue/database.sqlite ".backup '/secure-backup/showerqueue.sqlite'"`) or stop writes and checkpoint WAL before copying. Copying only a live `.sqlite` file while ignoring its WAL may lose committed data. For MySQL use a transaction-consistent dump. Encrypt backups, limit access, and test restoration to a separate instance. Back up environment keys through a separate secure channel.

For a release, test/build the new source before changing the live deployment. Back up, enter maintenance, stop the scheduler and drain/stop workers, deploy source and assets, run `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, rebuild config/routes/views, restart the worker (`queue:restart` under your service manager), restart scheduling, then leave maintenance. Use `php artisan down`/`up` appropriately. Change the `CACHE` version in `public/sw.js` when changing offline-shell behavior; Vite asset filenames already change with content. Never cache authenticated HTML in your CDN/reverse proxy.

Preserve the previous release and database backup. Database migrations may require restoring the matching backup to roll back; do not run destructive rollbacks against occupied household data casually.

## Release acceptance

Before inviting all phones, verify on the deployed URL:

1. Sign-in, first credential change, and child rejection from `/household`.
2. Two devices attempting the same slot: exactly one reservation and a clear conflict on the other.
3. A failed move preserves its old reservation; an accepted swap updates both devices.
4. Attendance matches your alternating-weekend reference and a holiday exception.
5. A live timer ends at the scheduled boundary even when started late.
6. Install on iPhone/Android, explicitly enable push, verify a reminder and a swap request, then test an opted-out notification type.
7. Offline navigation shows the fallback and cannot create, change, or accept a booking/swap. Reconnection shows the latest database schedule.
8. Confirm queue and cron processes survive server reboot and a backup can be restored.

Local automated tests and browser QA do not substitute for real-device push delivery or your host’s TLS and process configuration.

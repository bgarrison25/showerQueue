# ShowerQueue

A private, mobile-first shower schedule for Bill, Aleasha, Liam, Haydon, Elliana, and Anabelle. One household, one shower, no public registration.

Built with **Laravel 12, PHP 8.3+, Livewire 3, Tailwind CSS 4, and SQLite**. MySQL 8.0.16+ is supported. The dependency lock targets PHP 8.3 compatibility.

## Start locally

PHP needs PDO SQLite (or PDO MySQL), mbstring, XML, OpenSSL, cURL, fileinfo, intl, and bcmath. Node 22+ and Composer 2 are recommended. GD is required for profile photo processing and for regenerating the included PNG app icons.

```sh
composer install
```

Copy `.env.example` to `.env` (`Copy-Item .env.example .env` on PowerShell, `cp .env.example .env` on macOS/Linux). Then:

```sh
php artisan key:generate
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate
php artisan showerqueue:setup
npm install
npm run build
composer run dev
```

Open [http://localhost:8000](http://localhost:8000). `composer run dev` runs the web server, Vite, a queue worker, and the scheduler together. Alternatively, run `php artisan serve` after building assets; add `php artisan queue:work` and `php artisan schedule:work` in separate terminals for background processing. The Windows PHP development server is single-process; production uses PHP-FPM.

The setup command creates exactly six accounts and prints a **different cryptographically random one-time password for each member**. Save them in a password manager and share each privately. Only hashes are stored. Every member must choose a new password or a 6–12 digit PIN on first sign-in. There are no committed passwords, PINs, setup tokens, or public setup endpoints. Running setup again cannot replace existing accounts. `php artisan db:seed` invokes the same secure setup.

For server-operator recovery:

```sh
php artisan showerqueue:reset-access Bill
```

This generates another one-time password, revokes that member’s sessions and push subscriptions, and requires a credential change. Parents can reset other members from Household; they change their own credentials under Preferences.

## What works

| Area | Behavior |
| --- | --- |
| Shared calendar | Daily schedule, date navigation, a week agenda, time-of-day filters, availability, owners, and 10-second visible-page polling. The first 12 matching slots appear initially; “Show all” reveals the rest. |
| Family calendar | Combined month view with the selected day's events directly below the grid. Parents add/edit/delete family plans; timed Busy events block shower slots, with owner alerts for existing overlaps. Sync announces new family events once to the household, including optional phone push. Requires Google connection setup and the scheduler. See [Google Calendar setup](docs/GOOGLE_CALENDAR.md). |
| Booking | One tap to reserve; one non-cancelled reservation per person per household calendar day. Parent overrides allow extra bookings. Overlaps are always rejected. |
| Changes | Move an upcoming reservation without cancelling first. A failed move leaves its original time and database claims untouched. Parents can manage any booking. |
| Swaps | Request from someone else’s time in the day calendar. Recipient accepts or declines in Swaps. Parents can approve any request. Exchanges commit atomically; requests expire after 12 hours or before either shower starts. |
| Attendance | Parents home daily; children Monday, Tuesday, and alternate Friday–Sunday weekends. Parents configure an at-home Friday and one-day exceptions. Absence restricts booking only when enabled. |
| Recurring times | Parent-only weekly patterns for any member. Create, edit, or remove patterns. Generate 30 days ahead and report skipped conflicts without replacing existing reservations. |
| Fairness | Optional weekly cap on bookings before 9 AM, Monday–Sunday. Disabled initially. Valid swaps and explicit parent overrides bypass this cap. |
| Timer | Start during a reservation, synchronized server-based countdown, finish early, parent stop. A late start still ends at the reserved end time. |
| Profiles | Dedicated Profile screen with editable first name, optional nickname and last name, a live display-name preview, photos, and links to account settings. Names display as First “Nickname” Last inside household views. The existing sign-in name stays unchanged. |
| Profile photos | Profile → Your profile photo offers a camera picker and a photo-library picker, preview, save, cancel, and remove. Photos are resized in the browser and re-encoded to 512 × 512 JPEGs on the server. Images use the configured default filesystem disk and are served only to authenticated, active household members; initials remain the fallback. |
| Notifications | Account preferences and household defaults, an in-app inbox, optional queued Web Push, upcoming reminder (5 minutes by default), and next-turn notification. |
| PWA | Manifest, included 192/512 PNG icons, standalone mode, static-asset caching, offline fallback, explicit push permission, and light/dark appearance. |
| Parent dashboard | Hours, duration, timezone, notifications, attendance, recurrences, conflicts, swaps, account access, and reservation history with day filtering. |

The current shower status describes **now**, even while viewing a future date. The next-open-time card describes the selected date. A reserved time is shown as reserved until its owner starts the optional timer. Finishing early marks the shower free; it does not move later reservations or turn the remainder into a new bookable interval.

## Scheduling invariants

`app/Services/BookingService.php` owns all reservation, move, swap, and timer writes. Every such action first updates the singleton household lock row inside a database transaction. SQLite obtains its write lock **before any booking reads** (WAL, 10-second busy timeout); InnoDB holds the same row lock to commit. Retryable deadlocks are retried by Laravel.

Two database uniqueness constraints back up the service checks:

- A **stored generated daily key**, derived from owner and local day, is unique for every non-cancelled, non-override reservation. Direct duplicate daily inserts fail too. Finished reservations continue to count toward the daily allowance; cancelling releases it.
- `reservation_minutes.minute` is a unique UTC minute claim for each scheduled interval. Booking creation and its claims commit together. Cancellation deletes the claims. All supported durations and starts use whole minutes. Back-to-back intervals are allowed. Parent overrides never bypass time claims.

Always use the service for application writes; raw reservation inserts do not create minute claims. This is a single-household application, so one short database mutex is simpler and safer than separate distributed locks. Notifications are written to the inbox in the transaction; network push is queued after commit.

A move validates its replacement before releasing the old claims. A swap keeps both intervals and minute claims in place, exchanges owners under the same lock, and releases/reacquires generated daily keys inside that transaction. It rechecks revisions, owners, consent, expiry, future times, and daily limits. A booking change expires any affected pending swaps and notifies both participants. A parent override does not allow a swap to leave someone with multiple daily reservations.

All instants are stored in **UTC**; `day` is the booking’s date in the household timezone (default `America/Toronto`). Slots are stepped along the UTC timeline between local opening/closing hours. Spring-forward gaps are omitted; fall-back repeats have separate UTC IDs and EST/EDT labels. Recurrences choose the first occurrence of an ambiguous repeated local time. An unavailable/nonexistent local recurrence time is recorded as a conflict. Attendance uses calendar-day arithmetic independent of DST.

Changing scheduling hours or duration preserves existing bookings and checks new slots for partial overlaps against them. Timezone changes are blocked while upcoming reservations exist. Historical dates remain as originally booked. Editing a recurrence cancels and rebuilds its future generated bookings; removing it cancels only future generated bookings. Manually cancelled or moved occurrences are not recreated by the nightly run. Recorded conflicts are reviewed by editing the pattern; they are not silently retried each minute.

## Notifications and push

The in-app inbox works immediately. For phone push:

```sh
php artisan webpush:vapid
```

Set `VAPID_SUBJECT` to a `mailto:` contact or your HTTPS app URL. Keep the generated public/private keys in your deployment’s environment and keep them stable. Then clear/rebuild config, start the queue worker and scheduler, and open **Preferences → Enable phone notifications** on each device. Push permission is requested only from that button. On iOS/iPadOS 16.4+, install to the Home Screen before enabling push; Android requires a supported browser. Production needs HTTPS. See [WebKit’s platform requirements](https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/) and the [Web Push channel documentation](https://github.com/laravel-notification-channels/webpush).

The minute scheduler sends the upcoming reminder within the configured window. “Next” means the first unstarted upcoming/current reservation within 30 minutes. A unique `(user_id, event_key)` ledger prevents duplicate inbox/reminder creation. Event keys include booking revision, so accepted swaps notify the new owner correctly. Stale queued reminders for cancelled/changed reservations are discarded. Push uses stable notification tags to replace retry duplicates. Push delivery is best-effort: the durable inbox is authoritative, and delayed workers/devices can delay push. Only trusted browser push provider endpoints are accepted; redirects are disabled.

Offline pages never cache household HTML, calendar data, Livewire requests, authentication responses, or mutations. Static Vite assets are cached by hashed URL. A disconnected navigation shows a generic offline page; open Livewire views show an offline banner and disable booking buttons. Changes are never stored in an offline queue. The server is always authoritative.

## Verification

```sh
php artisan test --compact
npm run build
composer audit --locked
npm audit
```

Tests use a separate in-memory SQLite database by default and never seed the real household database. The concurrency test launches **two real PHP processes** against its own temporary SQLite file and requires exactly one winner. The suite covers daily limits, exact and partial overlaps, moves and cancellation, parent overrides, swap consent/rejection/expiry, recurrence authorization and conflicts, attendance parity and exceptions, notification preferences and deduplication, timezone/DST behavior, timers, UI rendering, and authorization/tampering.

The GitHub Actions workflow runs application tests on PHP 8.3 against SQLite and MySQL 8.4. Local validation in this workspace used PHP 8.5/SQLite; MySQL CI must run on your repository before treating that backend as verified. Real iOS/Android installation and delivery also need device testing on your HTTPS deployment. Browser QA used a separate ignored `.preview` database; it contains demonstration bookings, not your production schedule.

See [deployment instructions](docs/DEPLOYMENT.md) for HTTPS, MySQL, workers, backups, and updates. Laravel’s [deployment guidance](https://laravel.com/docs/12.x/deployment) and [Livewire security guidance](https://livewire.laravel.com/docs/3.x/security) describe the framework conventions used here.

Profile photos use `profile-photos/` on the configured default filesystem disk. The live deployment is Laravel Cloud: attach a private Object Storage bucket as the default disk so uploads survive deployments. Local development uses `storage/app/private/profile-photos`. Back up photos alongside the database. Apply migrations when updating an existing installation. The phone picker accepts photos up to 20 MB and resizes them before upload, keeping the server upload below the default 2 MB PHP limit. JPG, PNG and WebP work across supported browsers; HEIC is converted when the browser can decode it (otherwise the picker asks for a JPG/PNG/WebP copy). Native camera capture depends on the phone/browser; desktop browsers use a file picker.


### UI conventions

- Use the shared `app-navigation` component for sidebar and secondary mobile destinations. Group daily plans, household tools, and personal account pages; show household management only to parents.
- Keep Schedule, Calendar, Swaps, and Inbox directly accessible on phones. Menu contains Members, Profile, Settings, and parent management. Use the same labels and destinations at every screen size.
- Give each page a descriptive heading that matches its navigation label. Mark only the actual current destination with `aria-current="page"`; do not select Profile while showing Settings.
- Use semantic navigation lists, visible keyboard focus, and text labels alongside icons. Active links also use a border and heavier text, so color is not the only cue.
- Aim for at least 44-pixel controls and keep narrow-screen forms within the viewport. Tables may scroll inside their own container.
- Reuse avatar, feedback, and status components. Keep status text visible, provide success/error feedback, and preserve unsaved work during related actions.
- Check new screens at 320-pixel phone width and desktop sizes, with keyboard navigation, long names, both appearance modes, and both permission levels.

References: [NN/g menu-design checklist](https://www.nngroup.com/articles/menu-design/), [W3C menu structure](https://www.w3.org/WAI/tutorials/menus/structure/), and [WCAG 2.2 target-size guidance](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html). These conventions guide implementation; they are not a claim of a complete accessibility conformance audit.

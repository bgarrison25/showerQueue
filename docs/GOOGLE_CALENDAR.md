# Shared family calendar

ShowerQueue can use one private shared Google Calendar for family plans and shower exports. The Google Calendar link identifies the calendar; it does not authorize the app. This integration uses a service account shared onto that calendar, without changing household sign-in or requiring each family member to connect a Google account.

## One-time Google setup

1. Open [Google Cloud Console](https://console.cloud.google.com/), create or select a project, and enable the [Google Calendar API](https://console.cloud.google.com/apis/library/calendar-json.googleapis.com).
2. Under **IAM & Admin → Service Accounts**, create a service account, such as `showerqueue-calendar`. It does not need a project-wide IAM role or domain-wide delegation to access a calendar explicitly shared with it.
3. Open that service account's **Keys** tab, choose **Add key → Create new key → JSON**, and save the downloaded file outside the project repository. This is a private credential. Do not commit it, attach it to chat, or put it under `public/`.
4. In Google Calendar, open the family calendar's **Settings and sharing → Shared with** (sometimes **Share with specific people or groups**). Add the service account's `client_email` from the JSON file. Allow **Make changes and see event details** / **Make changes to events**. Also share it with your spouse's Google account if they should edit from Google Calendar. Keep the calendar private.
5. Under **Integrate calendar**, copy **Calendar ID**, which commonly ends in `@group.calendar.google.com`. Use the ID, not the browser URL or an iCal subscription URL.

Google's references: [service accounts](https://developers.google.com/identity/protocols/oauth2/service-account), [sharing permissions](https://support.google.com/calendar/answer/37082), and [Calendar API events](https://developers.google.com/workspace/calendar/api/v3/reference/events).

## Laravel Cloud configuration

In your app's Cloud environment variables, set:

```dotenv
GOOGLE_CALENDAR_ID=your-calendar-id@group.calendar.google.com
GOOGLE_CALENDAR_CREDENTIALS_BASE64=the-base64-encoded-complete-json-key
GOOGLE_CALENDAR_BLOCK_BUSY=true
```

To copy the Base64 value on Windows without printing it into the terminal, run this in PowerShell, replacing the path with your downloaded key:

```powershell
[Convert]::ToBase64String([IO.File]::ReadAllBytes('C:\path\to\your-service-account.json')) | Set-Clipboard
```

Paste it into `GOOGLE_CALENDAR_CREDENTIALS_BASE64`. Base64 is an encoding, not encryption; protect this variable as a secret. No key is shipped with the code. Use different calendars for local/staging and production so test bookings do not appear in the family calendar.

Deploy the new code with this deployment command (keep your existing asset-build commands):

```sh
php artisan migrate --force
```

Enable Laravel Cloud's **Scheduler** for the environment and configure a background queue worker for phone push (`php artisan queue:work --sleep=1 --tries=3 --timeout=45`). Use a shared persistent cache such as the existing database cache so locks and tokens work across replicas. Restart/redeploy after environment-variable changes to refresh cached configuration. Do not paste the credential into an Artisan command.

In Cloud's **Commands** tab, run:

```sh
php artisan showerqueue:calendar-sync
```

Then sign in as a parent and open **Calendar**. **Sync now** runs the same check, limited to once a minute. The screen shows when shower exports last synced. This scheduled command itself does not need a queue worker; enabled phone push does. The in-app inbox always works. For phone alerts, configure VAPID and enable notifications on each device as described in the README. Keep the environment awake when timely background processing is required; hibernation or stopped scheduler/workers can delay sync and alerts.

## How it behaves

- Everyone in the signed-in household can see the combined month calendar and a day's agenda directly below the grid. Selecting a day updates that section and brings it into view when necessary. Only active parent accounts can create, edit, or delete family events. All events on the shared calendar are household-visible.
- Google is the source for family events. Add them in Google or in ShowerQueue; the app refreshes them approximately every 45–60 seconds while open. Refresh plans fetches again. No authenticated family data is cached offline.
- Parents can enter a title, start/end, an optional place and notes, all-day dates, and Busy/Free status. Multi-day all-day events use an inclusive last day in the form and Google's exclusive end date on the API. Recurring events created in Google are displayed; editing/deleting an instance from the app affects that occurrence only. Create or manage a whole recurring family series in Google Calendar. Invitees and special Google event types are managed in Google.
- **Timed Busy events block new shower bookings for everyone**, including partially overlapping slots and generated recurring showers. Back-to-back events are allowed. Free events and all-day entries, such as birthdays, remain informational. Set `GOOGLE_CALENDAR_BLOCK_BUSY=false` to keep the combined calendar and alerts while disabling booking blocks.
- The slot display uses a short cache, but a booking or move checks Google freshly before taking the household transaction lock. During a Google outage, normal new bookings and moves fail safely; existing bookings remain intact and can still be cancelled. Parents can explicitly use **Parent override** to bypass family blocks or connection failures. Overlapping shower reservations are always forbidden.
- Existing showers are never automatically cancelled or moved when a family plan changes. A timed Busy event that overlaps a booked/running shower produces an inbox alert for its owner, plus optional queued phone push. The new **Family events overlapping your shower** preference controls these alerts. All-day/Free events don't alert. A unique key per event interval, booking interval, and owner prevents repeat alerts each minute. Queued alerts recheck the overlap before phone delivery.
- Each sync also announces newly discovered current/upcoming family events to active household members through the inbox and optional phone push. **New family calendar events** in Preferences controls these announcements independently of overlap alerts. All-day and Free events are included. The first sync after this update announces existing upcoming events once; subsequent syncs and edits do not repeat them. A recurring series is announced once with its next occurrence, rather than announcing every occurrence. Announcements cover the same 90-day horizon as overlap checks and use a persistent database ledger, so cache resets and repeated/manual syncs do not resend them. Cancelled, past, malformed, and shower-export events are excluded.
- The scheduler checks overlaps through the booking horizon (90 days). It publishes recent/upcoming shower reservations to Google, updates owners after swaps, and removes cancelled/moved copies. IDs are persisted before API writes, so a retry after a lost response does not create a duplicate. Shower copies are excluded from family blocking and displayed once in the app.
- Shower changes belong in ShowerQueue so booking constraints remain enforced. Editing a shower copy in Google does not change the reservation; the exporter reconciles Google copies after app changes and at least every six hours while those bookings remain current. Google-deleted shower copies can be recreated. Historical exports remain as history.
- Google and the app cannot share a transaction. An event added immediately after a booking's Google check can still create a conflict; the next background check alerts its owner. Google/API availability and running background processes determine sync timing.
- Event editing/deletion uses Google's ETag preconditions. If another person changes an event, reopen it before saving; the app preserves their changes rather than overwriting them.

## Troubleshooting and verification

- **Not connected:** both environment variables must be present, followed by a deployment/config refresh.
- **Missing or invalid key:** encode the entire downloaded JSON, including `type`, `client_email`, and `private_key`. The account type must be `service_account`.
- **Access denied:** enable the Calendar API in the key's project; share the exact calendar with the exact service-account email and grant permission to edit and see event details.
- **Calendar missing:** check the calendar's ID, not your personal primary calendar or the `cid=` browser parameter.
- **No exports or alerts:** check Scheduler, run `showerqueue:calendar-sync`, and review Cloud command output/logs. Phone delivery also needs a queue worker, VAPID, a supported device, and notification permission.
- **Changed calendar ID or service account:** existing exported events on the old calendar are not moved automatically. Remove old shower copies from Google if retiring that connection. Changing `APP_KEY` also changes the exporter instance marker; preserve your production app key.

Tests fake Google's HTTP endpoints; no test contacts your calendar or uses production credentials. They exercise signed service-account assertions, token refresh, pagination, parent authorization, conditional edits, retry deduplication, recurring-event instances, UTC/local time handling, busy/free/all-day blocking, failed moves, parent overrides, recurrence conflicts, shower exports, and deduplicated overlap alerts. Real Google access and phone delivery still require the one-time connection and a deployment/device check.

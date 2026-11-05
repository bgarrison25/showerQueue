<?php

namespace App\Services;

use App\Exceptions\CalendarException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleCalendar
{
    public function configured(): bool
    {
        return filled(config('google-calendar.id')) && filled(config('google-calendar.credentials'));
    }

    public function calendarKey(): string
    {
        return hash('sha256', (string) config('google-calendar.id'));
    }

    public function instance(): string
    {
        return substr(hash('sha256', (string) config('app.key')), 0, 32);
    }

    public function calendarUrl(): string
    {
        return 'https://calendar.google.com/calendar/u/0?cid='.rawurlencode(base64_encode((string) config('google-calendar.id')));
    }

    public function invalidate(): void
    {
        Cache::put('google-calendar:version:'.$this->calendarKey(), (string) Str::uuid(), now()->addDays(2));
    }

    public function events(CarbonImmutable $start, CarbonImmutable $end, bool $fresh = false): array
    {
        if (!$this->configured()) return [];

        $key = 'google-calendar:events:'.hash('sha256', $this->calendarKey().config('google-calendar.credentials').$start->toIso8601String().$end->toIso8601String().Cache::get('google-calendar:version:'.$this->calendarKey(), 'initial'));
        $fetch = function () use ($start, $end) {
            $events = []; $page = null;
            for ($i = 0; $i < 20; $i++) {
                $data = $this->request('GET', '', array_filter([
                    'timeMin' => $start->utc()->toRfc3339String(),
                    'timeMax' => $end->utc()->toRfc3339String(),
                    'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 250,
                    'pageToken' => $page,
                ]))->json();
                if (!in_array($data['accessRole'] ?? '', ['writer', 'owner'])) {
                    throw new CalendarException('Google Calendar needs sharing permission to make changes and see event details.');
                }
                foreach ($data['items'] ?? [] as $event) {
                    if (($event['status'] ?? '') !== 'cancelled') $events[] = $event;
                }
                $page = $data['nextPageToken'] ?? null;
                if (!$page) return $events;
            }
            throw new CalendarException('There are too many calendar events in this view. Choose a smaller date range.');
        };
        if ($fresh) {
            $events = $fetch();
            Cache::put($key, $events, 45);
            return $events;
        }
        return Cache::remember($key, 45, $fetch);
    }

    public function get(string $id): ?array
    {
        $response = $this->request('GET', '/'.$this->eventId($id), allowed: [404, 410]);
        return in_array($response->status(), [404, 410]) || $response->json('status') === 'cancelled' ? null : $response->json();
    }

    public function insert(array $data): array
    {
        $response = $this->request('POST', '', $data, allowed: [409]);
        if ($response->status() === 409) {
            $existing = $this->get($data['id']);
            if ($existing && data_get($existing, 'extendedProperties.private') === data_get($data, 'extendedProperties.private')) return $existing;
            throw new CalendarException('That calendar event changed. Refresh the calendar and try again.');
        }
        return $response->json();
    }

    public function update(string $id, array $data, string $etag): array
    {
        return $this->request('PATCH', '/'.$this->eventId($id), $data, ['If-Match' => $etag])->json();
    }

    public function delete(string $id, ?string $etag = null): void
    {
        $this->request('DELETE', '/'.$this->eventId($id), headers: $etag ? ['If-Match' => $etag] : [], allowed: [404, 410]);
    }

    public function isShower(array $event): bool
    {
        return data_get($event, 'extendedProperties.private.showerqueue') !== null;
    }

    public function interval(array $event): ?array
    {
        try {
            $timezone = \App\Models\HouseholdSetting::current()->timezone;
            $allDay = isset($event['start']['date']);
            $start = $event['start']['dateTime'] ?? $event['start']['date'] ?? null;
            $end = $event['end']['dateTime'] ?? $event['end']['date'] ?? null;
            if (!$start || !$end) return null;
            $start = CarbonImmutable::parse($start, $event['start']['timeZone'] ?? $timezone);
            $end = CarbonImmutable::parse($end, $event['end']['timeZone'] ?? $timezone);
            return $start->lt($end) ? [$start, $end, $allDay] : null;
        } catch (\Throwable) { return null; }
    }

    private function eventId(string $id): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{5,1024}$/', $id)) throw new CalendarException('Choose a valid calendar event.');
        return rawurlencode($id);
    }

    private function credentials(): array
    {
        try {
            $data = json_decode(base64_decode((string) config('google-calendar.credentials'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
            if (($data['type'] ?? '') !== 'service_account' || empty($data['client_email']) || empty($data['private_key'])) throw new \RuntimeException;
            return $data;
        } catch (\Throwable) {
            throw new CalendarException('The Google Calendar service-account key is missing or invalid. Ask a parent to check the connection setup.');
        }
    }

    private function tokenKey(): string
    {
        return 'google-calendar:token:'.hash('sha256', (string) config('google-calendar.credentials'));
    }

    private function token(): string
    {
        return Cache::remember($this->tokenKey(), 3300, function () {
            $credentials = $this->credentials();
            $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $header = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $encode(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/calendar.events',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => now()->timestamp, 'exp' => now()->addHour()->timestamp,
            ]));
            $input = $header.'.'.$claims;
            if (!@openssl_sign($input, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new CalendarException('Google Calendar could not authenticate. Check the service-account key.');
            }
            try {
                $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(15)->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $input.'.'.$encode($signature),
                ]);
            } catch (ConnectionException) {
                throw new CalendarException('Google Calendar is unavailable right now. Please try again shortly.');
            }
            if (!$response->successful() || !is_string($response->json('access_token'))) throw new CalendarException('Google Calendar authentication failed. Check the service-account key and server clock.');
            return $response->json('access_token');
        });
    }

    private function request(string $method, string $path = '', array $data = [], array $headers = [], array $allowed = []): Response
    {
        if (!$this->configured()) throw new CalendarException('Google Calendar is not connected yet.');
        $url = 'https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode((string) config('google-calendar.id')).'/events'.$path;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::withToken($this->token())->acceptJson()->withHeaders($headers)->connectTimeout(5)->timeout(15)
                    ->send($method, $url, $method === 'GET' ? ['query' => $data] : ['json' => $data]);
            } catch (ConnectionException) {
                throw new CalendarException('Google Calendar is unavailable right now. Please try again shortly.');
            }
            if ($response->status() !== 401 || $attempt === 1) break;
            Cache::forget($this->tokenKey());
        }
        if ($response->successful() || in_array($response->status(), $allowed)) return $response;
        throw new CalendarException(match ($response->status()) {
            401 => 'Google Calendar authentication expired. Check the connection setup.',
            403 => 'Google Calendar access was denied. Enable the Calendar API and share the calendar with permission to make changes and see event details.',
            404, 410 => 'This calendar or event is no longer available. Refresh and try again.',
            412 => 'Someone changed this event. Reopen it before saving so their changes are preserved.',
            default => 'Google Calendar could not complete the request. Please try again shortly.',
        });
    }
}

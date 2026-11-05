<?php

namespace Tests\Feature;

use App\Exceptions\CalendarException;
use App\Services\GoogleCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_account_assertions_are_signed_and_tokens_are_cached_and_refreshed(): void
    {
        // Herd on Windows may not install a global openssl.cnf. Key generation
        // uses its own temporary configuration; no credentials are committed.
        $configPath = tempnam(sys_get_temp_dir(), 'sq-openssl-');
        file_put_contents($configPath, "[req]\ndistinguished_name=req_dn\n[req_dn]\n");
        try {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $configPath]);
            $this->assertNotFalse($key);
            $this->assertTrue(openssl_pkey_export($key, $privateKey, options: ['config' => $configPath]));
        } finally { unlink($configPath); }
        $publicKey = openssl_pkey_get_details($key)['key'];
        config(['google-calendar.id' => 'test@group.calendar.google.com', 'google-calendar.credentials' => base64_encode(json_encode(['type' => 'service_account', 'client_email' => 'calendar@example.iam.gserviceaccount.com', 'private_key' => $privateKey]))]);
        $tokenCalls = 0; $eventCalls = 0;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$tokenCalls, &$eventCalls, $publicKey) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                $tokenCalls++;
                [$header, $claims, $signature] = explode('.', $request['assertion']);
                $decode = fn ($value) => base64_decode(strtr($value, '-_', '+/'));
                $this->assertSame(1, openssl_verify($header.'.'.$claims, $decode($signature), $publicKey, OPENSSL_ALGO_SHA256));
                $data = json_decode($decode($claims), true);
                $this->assertSame('https://www.googleapis.com/auth/calendar.events', $data['scope']);
                $this->assertSame('https://oauth2.googleapis.com/token', $data['aud']);
                $this->assertSame(3600, $data['exp'] - $data['iat']);
                $this->assertSame('calendar@example.iam.gserviceaccount.com', $data['iss']);
                return Http::response(['access_token' => 'token-'.$tokenCalls, 'expires_in' => 3600]);
            }
            $eventCalls++;
            if ($eventCalls === 2) return Http::response([], 401);
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer token-'.$tokenCalls));
            return Http::response(['accessRole' => 'writer', 'items' => []]);
        });
        $google = app(GoogleCalendar::class);
        $start = CarbonImmutable::now()->startOfDay();
        $google->events($start, $start->addDay(), true);
        $google->events($start, $start->addDay(), true);
        $google->events($start, $start->addDay(), true);
        $this->assertSame(2, $tokenCalls);
        $this->assertSame(4, $eventCalls);
    }

    public function test_all_google_event_pages_are_read_and_cancelled_entries_are_ignored(): void
    {
        config(['google-calendar.id' => 'test@example.test', 'google-calendar.credentials' => 'fake-key']);
        Cache::put('google-calendar:token:'.hash('sha256', 'fake-key'), 'token', 3300);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            return ($request->data()['pageToken'] ?? null) === 'second' ? Http::response(['accessRole' => 'writer', 'items' => [['id' => 'second-event'], ['id' => 'deleted-event', 'status' => 'cancelled']]])
                : Http::response(['accessRole' => 'writer', 'items' => [['id' => 'first-event']], 'nextPageToken' => 'second']);
        });
        $start = CarbonImmutable::now()->startOfDay();
        $this->assertSame(['first-event', 'second-event'], array_column(app(GoogleCalendar::class)->events($start, $start->addDay()), 'id'));
        Http::assertSentCount(2);
    }

    public function test_google_errors_are_sanitized_and_incomplete_results_are_never_cached(): void
    {
        config(['google-calendar.id' => 'test@example.test', 'google-calendar.credentials' => 'fake-key']);
        Cache::put('google-calendar:token:'.hash('sha256', 'fake-key'), 'token', 3300);
        Http::preventStrayRequests();
        Http::fakeSequence()->push(['error' => ['message' => 'sensitive-provider-details']], 403)->push(['accessRole' => 'writer', 'items' => []]);
        $start = CarbonImmutable::now()->startOfDay();
        try { app(GoogleCalendar::class)->events($start, $start->addDay()); $this->fail('Expected access denial'); }
        catch (CalendarException $e) { $this->assertStringNotContainsString('sensitive-provider-details', $e->getMessage()); }
        $this->assertSame([], app(GoogleCalendar::class)->events($start, $start->addDay()));
        Http::assertSentCount(2);
    }

    public function test_delete_sends_no_body_and_preserves_the_google_etag_precondition(): void
    {
        config(['google-calendar.id' => 'shared@example.test', 'google-calendar.credentials' => 'fake-key']);
        Cache::put('google-calendar:token:'.hash('sha256', 'fake-key'), 'token', 3300);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            // Google's DELETE endpoint rejects even an empty JSON array.
            if ($request->body() !== '') return Http::response(['error' => ['message' => 'Invalid JSON payload received.']], 400);
            return Http::response(null, 204);
        });
        app(GoogleCalendar::class)->delete('googleevent_20261109T110500Z', '"6383450978000000"');
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->body() === ''
            && $request->header('If-Match')[0] === '"6383450978000000"'
            && str_ends_with($request->url(), '/googleevent_20261109T110500Z'));
    }
}

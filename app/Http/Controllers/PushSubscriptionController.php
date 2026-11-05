<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PushSubscriptionController extends Controller
{
    public function store(Request $request)
    {
        abort_unless(config('webpush.vapid.public_key'), 503, 'Push is not configured yet. Your inbox still works.');
        $data = $request->validate(['endpoint' => 'required|url:https|max:2048', 'keys.p256dh' => 'required|string|max:255', 'keys.auth' => 'required|string|max:255']);
        // Only trusted push providers can be contacted by the queue worker (SSRF protection).
        $host = strtolower(parse_url($data['endpoint'], PHP_URL_HOST) ?? '');
        if (!in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com']) && !str_ends_with($host, '.notify.windows.com')) throw ValidationException::withMessages(['endpoint' => 'This push provider is not supported. Use your in-app inbox.']);
        if (parse_url($data['endpoint'], PHP_URL_PORT) && parse_url($data['endpoint'], PHP_URL_PORT) !== 443) abort(422);
        $request->user()->updatePushSubscription($data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth'], 'aes128gcm');
        return response()->json(['saved' => true]);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|string|max:2048']);
        $request->user()->deletePushSubscription($data['endpoint']);
        return response()->noContent();
    }
}

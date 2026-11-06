<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

class ProfilePhotoController extends Controller
{
    public function __invoke(User $user)
    {
        abort_unless($user->is_active && $user->profile_photo_path, 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($user->profile_photo_path), 404);

        return $disk->response($user->profile_photo_path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

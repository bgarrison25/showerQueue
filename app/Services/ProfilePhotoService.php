<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfilePhotoService
{
    public function save(User $user, UploadedFile $photo): void
    {
        // Check content and dimensions even when a client bypasses the phone resizer.
        Validator::make(['photo' => $photo], ['photo' => [
            'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048',
            'dimensions:min_width=32,min_height=32,max_width=2048,max_height=2048',
        ]])->validate();

        if (!extension_loaded('gd')) {
            throw ValidationException::withMessages(['photo' => 'Photo processing is unavailable. Please ask a parent to check server setup.']);
        }
        $image = @imagecreatefromstring($photo->get());
        if (!$image) {
            throw ValidationException::withMessages(['photo' => 'This photo could not be read. Choose a JPG, PNG, or WebP image.']);
        }

        // A new raster removes EXIF/location data and any original embedded content.
        // The picker already handles camera orientation and shows this centered crop.
        $size = min(imagesx($image), imagesy($image));
        $square = imagecreatetruecolor(512, 512);
        imagefill($square, 0, 0, imagecolorallocate($square, 248, 247, 244));
        imagecopyresampled($square, $image, 0, 0, (int) ((imagesx($image) - $size) / 2), (int) ((imagesy($image) - $size) / 2), 512, 512, $size, $size);
        ob_start();
        imagejpeg($square, null, 88);
        $contents = ob_get_clean();
        $path = 'profile-photos/'.Str::uuid().'.jpg';
        if (!Storage::disk()->put($path, $contents)) {
            throw ValidationException::withMessages(['photo' => 'Your photo could not be saved. Please try again.']);
        }

        try {
            $previous = $this->replacePath($user, $path);
        } catch (\Throwable $e) {
            Storage::disk()->delete($path);
            throw $e;
        }
        if ($previous) Storage::disk()->delete($previous);
    }

    public function remove(User $user): void
    {
        $previous = $this->replacePath($user, null);
        if ($previous) Storage::disk()->delete($previous);
    }

    private function replacePath(User $user, ?string $path): ?string
    {
        return DB::transaction(function () use ($user, $path) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->profile_photo_path;
            $locked->forceFill(['profile_photo_path' => $path])->save();
            return $previous;
        }, 3);
    }
}

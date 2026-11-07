<?php

namespace Tests\Feature;

use App\Livewire\Profile;
use App\Models\User;
use App\Services\ProfilePhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_member_can_save_a_private_resized_photo_only_for_their_account(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        Livewire::actingAs($member)->test(Profile::class)
            ->set('photo', UploadedFile::fake()->image('camera.png', 900, 600))
            ->call('savePhoto')->assertHasNoErrors()->assertDispatched('profile-updated')->assertDispatched('profile-photo-saved');

        $member->refresh();
        $this->assertStringStartsWith('/members/', $member->profilePhotoUrl());
        $this->assertNull($other->fresh()->profile_photo_path);
        Storage::disk('local')->assertExists($member->profile_photo_path);
        $image = getimagesizefromstring(Storage::disk('local')->get($member->profile_photo_path));
        $this->assertSame([512, 512, 'image/jpeg'], [$image[0], $image[1], $image['mime']]);
        $this->assertArrayNotHasKey('profile_photo_path', $member->toArray());
        $this->actingAs($other)->get($member->profilePhotoUrl())->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/')->assertSee($member->profilePhotoUrl(), false);
    }

    public function test_replacing_and_removing_a_photo_cleans_up_old_images(): void
    {
        $member = User::factory()->create();
        $service = app(ProfilePhotoService::class);
        $service->save($member, UploadedFile::fake()->image('first.jpg', 100, 100));
        $oldPath = $member->fresh()->profile_photo_path;
        $oldUrl = $member->fresh()->profilePhotoUrl();
        $service->save($member, UploadedFile::fake()->image('second.webp', 100, 100));
        $newPath = $member->fresh()->profile_photo_path;
        $this->assertNotSame($oldUrl, $member->fresh()->profilePhotoUrl());
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);

        Livewire::actingAs($member->fresh())->test(Profile::class)->call('removePhoto')
            ->assertHasNoErrors()->assertDispatched('profile-updated')->assertDispatched('profile-photo-saved');
        $this->assertNull($member->fresh()->profile_photo_path);
        Storage::disk('local')->assertMissing($newPath);
        $this->get(route('profile-photo', $member))->assertNotFound();
    }

    public function test_cancel_discards_only_the_pending_upload(): void
    {
        $member = User::factory()->create();
        app(ProfilePhotoService::class)->save($member, UploadedFile::fake()->image('saved.jpg', 100, 100));
        $path = $member->fresh()->profile_photo_path;
        Livewire::actingAs($member)->test(Profile::class)
            ->set('photo', UploadedFile::fake()->image('pending.png', 100, 100))->call('discardPhoto')
            ->assertSet('photo', null)->assertHasNoErrors();
        $this->assertSame($path, $member->fresh()->profile_photo_path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_invalid_and_oversized_images_never_replace_a_saved_photo(): void
    {
        $member = User::factory()->create();
        app(ProfilePhotoService::class)->save($member, UploadedFile::fake()->image('saved.jpg', 100, 100));
        $path = $member->fresh()->profile_photo_path;
        foreach ([
            UploadedFile::fake()->createWithContent('fake.jpg', '<script>alert(1)</script>'),
            UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"/>'),
            UploadedFile::fake()->image('large.jpg', 100, 100)->size(2049),
            UploadedFile::fake()->image('wide.png', 2049, 40),
        ] as $invalid) {
            Livewire::actingAs($member)->test(Profile::class)->set('photo', $invalid)
                ->call('savePhoto')->assertHasErrors('photo');
            $this->assertSame($path, $member->fresh()->profile_photo_path);
        }
        Storage::disk('local')->assertExists($path);
    }

    public function test_guest_paused_and_first_login_accounts_cannot_read_household_photos(): void
    {
        $owner = User::factory()->create();
        app(ProfilePhotoService::class)->save($owner, UploadedFile::fake()->image('photo.jpg', 100, 100));
        $url = $owner->fresh()->profilePhotoUrl();
        $this->get($url)->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['is_active' => false]))->get($url)->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['must_change_password' => true]))->get($url)->assertRedirect('/password');
        $owner->forceFill(['is_active' => false])->save();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    }

    public function test_saved_file_is_reencoded_and_not_the_original_upload(): void
    {
        $member = User::factory()->create();
        $original = UploadedFile::fake()->image('photo.jpg', 80, 120)->get();
        $marker = 'original-file-metadata-marker';
        app(ProfilePhotoService::class)->save($member, UploadedFile::fake()->createWithContent('photo.jpg', $original.$marker));
        $saved = Storage::disk('local')->get($member->fresh()->profile_photo_path);
        $this->assertStringNotContainsString($marker, $saved);
    }
}

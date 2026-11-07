<?php

namespace Tests\Feature;

use App\Livewire\Profile;
use App\Livewire\ProfileLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_names_and_optional_parts_display_without_extra_spaces(): void
    {
        $member = User::factory()->create(['name' => 'Liam Garrison']);
        $this->assertSame('Liam Garrison', $member->display_name);
        $member->forceFill(['nickname' => 'Dash'])->save();
        $this->assertSame('Liam “Dash” Garrison', $member->display_name);
        $member->forceFill(['last_name' => ''])->save();
        $this->assertSame('Liam “Dash”', $member->display_name);
        $member->forceFill(['nickname' => null])->save();
        $this->assertSame('Liam', $member->display_name);
        $this->assertSame('Éva “É” de la Cruz', User::formatDisplayName('Éva', 'É', 'de la Cruz'));
    }

    public function test_profile_changes_only_the_current_members_display_fields_and_keeps_their_login(): void
    {
        $password = bin2hex(random_bytes(18));
        $member = User::factory()->create(['name' => 'Liam', 'password' => $password]);
        $other = User::factory()->create(['name' => 'Bill', 'is_admin' => true]);
        Livewire::actingAs($member)->test(Profile::class)
            ->set('firstName', ' Liam ')->set('nickname', ' Dash ')->set('lastName', 'Garrison')
            ->call('saveProfile')->assertHasNoErrors()->assertDispatched('profile-updated');
        $this->assertSame('Liam “Dash” Garrison', $member->fresh()->display_name);
        $this->assertSame('Liam', $member->fresh()->name);
        $this->assertFalse($member->fresh()->is_admin);
        $this->assertNull($other->fresh()->nickname);
        $this->assertNull($other->fresh()->first_name);
        $this->get('/')->assertOk()->assertSee('Liam “Dash” Garrison');
        $this->post('/logout');
        $this->post('/login', ['name' => 'Liam', 'password' => $password])->assertRedirect('/');
        $this->assertAuthenticatedAs($member);
    }

    public function test_blank_nickname_removes_it_and_last_name_is_optional(): void
    {
        $member = User::factory()->create(['name' => 'Liam', 'nickname' => 'Dash']);
        Livewire::actingAs($member)->test(Profile::class)->set('nickname', ' ')->set('lastName', '')
            ->call('saveProfile')->assertHasNoErrors();
        $this->assertNull($member->fresh()->nickname);
        $this->assertSame('Liam', $member->fresh()->display_name);
    }

    public function test_profile_rejects_blank_first_name_long_fields_and_markup(): void
    {
        $member = User::factory()->create(['name' => 'Liam']);
        foreach (['firstName' => ' ', 'lastName' => str_repeat('a', 81), 'nickname' => '<script>alert(1)</script>'] as $field => $value) {
            Livewire::actingAs($member)->test(Profile::class)->set($field, $value)->call('saveProfile')->assertHasErrors($field);
        }
        Livewire::actingAs($member)->test(Profile::class)->set('nickname', str_repeat('a', 31))
            ->call('saveProfile')->assertHasErrors('nickname');
        $this->assertNull($member->fresh()->first_name);
        $this->assertNull($member->fresh()->nickname);
    }

    public function test_profile_is_only_accessible_to_active_members_who_completed_first_login(): void
    {
        $this->get('/profile')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['is_active' => false]))->get('/profile')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['must_change_password' => true]))->get('/profile')->assertRedirect('/password');
        $this->actingAs(User::factory()->create())->get('/profile')->assertOk()->assertSee('Nickname');
    }

    public function test_sidebar_profile_refreshes_after_profile_changes(): void
    {
        $member = User::factory()->create(['name' => 'Liam']);
        $link = Livewire::actingAs($member)->test(ProfileLink::class)->assertSee('Liam');
        $member->forceFill(['nickname' => 'Dash', 'last_name' => 'Garrison'])->save();
        $link->dispatch('profile-updated')->assertSee('Liam “Dash” Garrison');
    }

    public function test_saving_a_photo_preserves_unsaved_name_fields(): void
    {
        Storage::fake('local');
        $member = User::factory()->create(['name' => 'Liam']);
        Livewire::actingAs($member)->test(Profile::class)->set('nickname', 'Dash')
            ->set('photo', UploadedFile::fake()->image('photo.png', 80, 80))->call('savePhoto')
            ->assertHasNoErrors()->assertSet('nickname', 'Dash')->assertSet('photo', null)
            ->assertDispatched('profile-photo-saved');
        $this->assertNull($member->fresh()->nickname);
        $this->assertNotNull($member->fresh()->profile_photo_path);
    }

    public function test_saving_profile_persists_the_pending_photo_across_a_fresh_request(): void
    {
        Storage::fake('local');
        $member = User::factory()->create(['name' => 'Liam']);
        Livewire::actingAs($member)->test(Profile::class)
            ->set('photo', UploadedFile::fake()->image('photo.png', 80, 80))->set('nickname', 'Dash')
            ->call('saveProfile')->assertHasNoErrors()
            ->assertSet('photo', null)->assertDispatched('profile-photo-saved');
        $this->assertSame('Dash', $member->fresh()->nickname);
        $saved = $member->fresh();
        Storage::disk('local')->assertExists($saved->profile_photo_path);
        $this->get('/profile')->assertOk()->assertSee($saved->profilePhotoUrl(), false);
        $this->get($saved->profilePhotoUrl())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }
}

<div class="profile-page">
    <div class="page-heading">
        <div><h1>Your profile</h1><p class="muted">Edit your name, nickname, and photo.</p></div>
        <a href="{{ route('preferences') }}" class="button button-secondary"><x-icon name="settings" size="19"/> Settings</a>
    </div>
    <x-feedback :notice="$notice"/>
    <div class="profile-grid">
        <section class="panel profile-name-panel" aria-labelledby="profile-name-heading">
            <div class="aside-title"><x-icon name="user"/><h2 id="profile-name-heading">Your name</h2></div>
            <p class="small muted">Add a nickname between your first and last name. Last name and nickname are optional.</p>
            <form wire:submit="saveProfile" class="form-stack" x-data>
                <div class="form-row">
                    <label>First name<input type="text" wire:model="firstName" autocomplete="given-name" maxlength="60" required aria-describedby="name-help"></label>
                    <label>Last name <span class="field-optional">Optional</span><input type="text" wire:model="lastName" autocomplete="family-name" maxlength="80"></label>
                </div>
                <label>Nickname <span class="field-optional">Optional</span><input type="text" wire:model="nickname" autocomplete="nickname" maxlength="30" placeholder="e.g. Dash" aria-describedby="nickname-help"></label>
                <p id="nickname-help" class="small muted">Leave it blank to show just your name.</p>
                <div class="profile-name-preview"><span class="card-label">HOW YOUR NAME WILL APPEAR</span><strong x-text="[$wire.firstName.trim(), $wire.nickname.trim() ? '“' + $wire.nickname.trim() + '”' : '', $wire.lastName.trim()].filter(Boolean).join(' ') || 'Your name'">{{ $displayPreview ?: 'Your name' }}</strong></div>
                <p class="small muted" id="name-help">You’ll still sign in as <strong>{{ auth()->user()->name }}</strong>.</p>
                <button type="submit" class="button button-primary" wire:loading.attr="disabled" wire:target="saveProfile" wire:offline.attr="disabled"><span wire:loading.remove wire:target="saveProfile">Save profile</span><span wire:loading wire:target="saveProfile">Saving…</span></button>
            </form>
        </section>
        @include('livewire.partials.profile-photo')
    </div>
    <section class="panel profile-account" aria-label="Account settings">
        <div><h2>Your account</h2><p class="muted small">{{ auth()->user()->is_admin ? 'Parent account' : 'Household member' }} · Private to your household</p></div>
        <div class="button-row">
            <a class="button button-secondary" href="{{ route('password.edit') }}">Change password or PIN <x-icon name="arrow" size="17"/></a>
            <a class="button button-secondary" href="{{ route('preferences') }}">Notifications &amp; appearance <x-icon name="settings" size="17"/></a>
            @can('admin')<a class="text-button" href="{{ route('household') }}">Manage household <x-icon name="users" size="17"/></a>@endcan
        </div>
    </section>
</div>

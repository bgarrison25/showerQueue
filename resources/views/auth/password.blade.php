@extends('layouts.app', ['title' => 'Change password or PIN'])
@section('content')
<div class="narrow-page">
    <h1>{{ auth()->user()->must_change_password ? 'Set your password or PIN' : 'Change password or PIN' }}</h1>
    @if(auth()->user()->must_change_password)
        <p class="muted">You’re signed in as {{ auth()->user()->name }}. Replace your temporary password to open the schedule.</p>
    @endif
    <section class="panel">
        @if($errors->any())<div class="error-notice" role="alert" tabindex="-1" autofocus><strong>Your password wasn’t changed.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" novalidate action="{{ route('password.update') }}" class="form-stack" x-data="{ show: false }">
            @csrf @method('PUT')
            <label>{{ auth()->user()->must_change_password ? 'Temporary password' : 'Current password or PIN' }}<input name="current_password" type="password" :type="show ? 'text' : 'password'" autocomplete="current-password" required aria-describedby="current-help"></label>
            <p id="current-help" class="small muted">{{ auth()->user()->must_change_password ? 'Enter the temporary password your parent sent you—the same one you just used to sign in.' : 'Enter the password or PIN you currently use to sign in.' }}</p>
            <label>New password or PIN<input name="password" type="password" :type="show ? 'text' : 'password'" autocomplete="new-password" required minlength="6" maxlength="128" aria-describedby="password-help"></label>
            <p id="password-help" class="small muted">Use 6–12 numbers for a PIN, or at least 12 characters for a password. It must be different from your current password.</p>
            <label>Confirm new password or PIN<input name="password_confirmation" type="password" :type="show ? 'text' : 'password'" autocomplete="new-password" required minlength="6" maxlength="128"></label>
            <label class="checkbox-label"><input type="checkbox" x-model="show"> Show passwords</label>
            <button type="submit" class="button button-primary">Save &amp; open schedule <x-icon name="arrow" size="18"/></button>
        </form>
    </section>
    @if(auth()->user()->must_change_password)<form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="text-button">Sign out</button></form>@endif
</div>
@endsection

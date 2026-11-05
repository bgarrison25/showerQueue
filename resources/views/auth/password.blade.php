@extends('layouts.app', ['title' => 'Secure your account'])
@section('content')
<div class="narrow-page"><span class="eyebrow">YOUR ACCOUNT</span><h1>{{ auth()->user()->must_change_password ? 'Make yourself at home.' : 'Update your access.' }}</h1><p class="muted">Choose a private 6–12 digit PIN or a password of at least 12 characters.</p><section class="panel"><form method="POST" action="{{ route('password.update') }}" class="form-stack">@csrf @method('PUT')
<label>Current password or PIN<input name="current_password" type="password" autocomplete="current-password" required></label>
<label>New password or PIN<input name="password" type="password" autocomplete="new-password" required minlength="6" maxlength="128"></label>
<label>Confirm new password or PIN<input name="password_confirmation" type="password" autocomplete="new-password" required minlength="6" maxlength="128"></label>
@if($errors->any())<div class="error-notice" role="alert">{{ $errors->first() }}</div>@endif
<button class="button button-primary">Save & continue <x-icon name="arrow" size="18"/></button>
</form></section></div>
@endsection

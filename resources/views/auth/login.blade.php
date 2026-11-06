@extends('layouts.app', ['title' => 'Sign in'])
@section('content')
<section class="auth-card"><h1>Sign in</h1><p class="muted">Choose your name and sign in to find your time.</p>
@if($members->isEmpty())<div class="notice">Your household is ready to be set up. Ask a parent to run <code>php artisan showerqueue:setup</code> on the server, then sign in with the generated credentials.</div>
@else
<form method="POST" action="/login" class="form-stack">@csrf
<label>Your name<select name="name" required autofocus><option value="">Choose a household member</option>@foreach($members as $member)<option @selected(old('name') === $member->name)>{{ $member->name }}</option>@endforeach</select></label>
<label>Password or PIN<input name="password" type="password" autocomplete="current-password" required maxlength="128" placeholder="Your private password or PIN"></label>
<label class="checkbox-label"><input type="checkbox" name="remember" value="1"> Keep me signed in on this device</label>
@if($errors->any())<div class="error-notice" role="alert">{{ $errors->first() }}</div>@endif
<button class="button button-primary button-full">Let’s find your time <x-icon name="arrow" size="18"/></button>
</form>
<p class="small muted">Forgot your access? A parent can reset it for you.</p>
@endif
</section>
@endsection

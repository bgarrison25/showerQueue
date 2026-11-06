@extends('layouts.app', ['title' => 'Menu'])
@section('content')
<div class="menu-page">
    <div class="page-heading menu-heading"><x-water-pattern class="menu-water-art"/><div><h1>Menu</h1><p class="muted">Household members, your profile, and settings.</p></div></div>
    <x-app-navigation context="menu" :daily="false"/>
    <form method="POST" action="{{ route('logout') }}" class="menu-signout">@csrf<button class="button button-secondary" type="submit"><x-icon name="logout" size="19"/> Sign out</button></form>
</div>
@endsection

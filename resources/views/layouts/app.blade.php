<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#176c60">
    <meta name="description" content="One shower. A smoother day. Your private household shower schedule.">
    <meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="/manifest.webmanifest"><link rel="icon" type="image/svg+xml" href="/icons/icon.svg"><link rel="apple-touch-icon" href="/icons/icon-192.png">
    <title>{{ $title ?? 'Welcome' }} · ShowerQueue</title>
    <script>document.documentElement.dataset.theme = localStorage.getItem('sq-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
<a href="#main" class="skip-link">Skip to content</a>
@auth
<div class="app-shell">
    <aside class="sidebar">
        <a href="/" class="brand"><span class="brand-mark"><x-icon name="drop" size="25"/></span><span>Shower<span class="brand-light">Queue</span><small>A little order. A better day.</small></span></a>
        <p class="nav-label">YOUR HOUSEHOLD</p>
        <nav class="desktop-nav" aria-label="Main navigation">
            @foreach([['schedule','drop','Showers'],['calendar','calendar','Calendar'],['swaps','swap','Swaps'],['inbox','bell','Inbox'],['preferences','settings','Preferences']] as [$route,$icon,$label])
            @if($route === 'inbox')
                <livewire:notification-link placement="sidebar" :active="request()->routeIs('inbox')" key="notification-sidebar"/>
            @else
                <a href="{{ route($route) }}" class="{{ request()->routeIs($route) ? 'active' : '' }}"><x-icon :name="$icon"/><span>{{ $label }}</span></a>
            @endif
            @endforeach
            @can('admin')<a href="{{ route('household') }}" class="{{ request()->routeIs('household') ? 'active' : '' }}"><x-icon name="home"/><span>Household</span><span class="parent-tag">PARENT</span></a>@endcan
        </nav>
        <div class="sidebar-note"><x-icon name="users" size="26"/><strong>One shower.<br>Room for everyone.</strong><p>A shared schedule that keeps your household in sync.</p></div>
        <div class="sidebar-bottom"><button class="theme-button" type="button" data-theme-toggle><x-icon name="sun" size="19"/> Change appearance</button><div class="profile"><span class="avatar color-{{ auth()->user()->color }}">{{ mb_substr(auth()->user()->name,0,1) }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->is_admin ? 'Parent account' : 'Household member' }}</small></div><form method="POST" action="{{ route('logout') }}">@csrf<button class="icon-button" title="Sign out" aria-label="Sign out"><x-icon name="logout" size="19"/></button></form></div></div>
    </aside>
    <div class="main-column">
        <header class="topbar"><a class="mobile-brand" href="/"><span class="brand-mark"><x-icon name="drop"/></span><strong>ShowerQueue</strong></a><span class="desktop-only topbar-label">A smoother routine starts here.</span><div class="topbar-right"><span class="sync-label"><i></i> Household in sync</span><livewire:notification-link placement="header" key="notification-header"/><button class="icon-button mobile-only" type="button" data-theme-toggle aria-label="Change appearance"><x-icon name="sun"/></button></div></header>
        <main id="main" class="page-content">{{ $slot ?? '' }}@yield('content')</main>
        <footer class="page-footer"><span>Made for your household. A little more harmony.</span><span>ShowerQueue <span class="footer-dot">·</span> Private by design</span></footer>
    </div>
</div>
<nav class="mobile-nav" aria-label="Mobile navigation">
    @foreach([['schedule','drop','Showers'],['calendar','calendar','Calendar'],['swaps','swap','Swaps'],['inbox','bell','Inbox'],['preferences','settings','You']] as [$route,$icon,$label])
        @if($route === 'inbox')
            <livewire:notification-link placement="mobile" :active="request()->routeIs('inbox')" key="notification-mobile"/>
        @else
            <a href="{{ route($route) }}" class="{{ request()->routeIs($route) ? 'active' : '' }}"><x-icon :name="$icon" size="21"/><span>{{ $label }}</span></a>
        @endif
    @endforeach
    @can('admin')<a href="{{ route('household') }}" class="{{ request()->routeIs('household') ? 'active' : '' }}"><x-icon name="home" size="21"/><span>Household</span></a>@endcan
</nav>
@else
<main id="main" class="auth-page"><a href="/login" class="brand auth-brand"><span class="brand-mark"><x-icon name="drop" size="27"/></span><span>Shower<span class="brand-light">Queue</span></span></a>{{ $slot ?? '' }}@yield('content')<p class="auth-footer">One shower. Six people. A little more harmony.</p></main>
@endauth
@livewireScripts
</body>
</html>

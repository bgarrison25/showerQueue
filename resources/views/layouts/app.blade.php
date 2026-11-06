<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#f8f7f4">
    <meta name="description" content="Your private household shower schedule.">
    <meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="/manifest.webmanifest"><link rel="icon" type="image/svg+xml" href="/icons/icon.svg"><link rel="apple-touch-icon" href="/icons/icon-192.png">
    <title>{{ $title ?? 'Welcome' }} · ShowerQueue</title>
    <script>(() => { let theme; try { theme = localStorage.getItem('sq-theme'); } catch {} theme = ['light', 'dark'].includes(theme) ? theme : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); document.documentElement.dataset.theme = theme; document.querySelector('meta[name=theme-color]').content = theme === 'dark' ? '#101416' : '#f8f7f4'; })();</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
<a href="#main" class="skip-link">Skip to content</a>
@if(auth()->check() && !auth()->user()->must_change_password)
<div class="app-shell">
    <aside class="sidebar">
        <div class="sidebar-brand-panel">
            <x-water-pattern class="sidebar-water-art"/>
            <a href="/" class="brand"><span class="brand-mark"><x-icon name="drop" size="25"/></span><span>Shower<span class="brand-light">Queue</span></span></a>
        </div>
        <x-app-navigation context="sidebar"/>
        <div class="sidebar-bottom">
            <div class="profile"><livewire:profile-link key="sidebar-profile"/></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="sidebar-signout" type="submit"><x-icon name="logout" size="19"/> Sign out</button></form>
        </div>
    </aside>
    <div class="main-column">
        <header class="topbar"><a class="mobile-brand" href="/"><span class="brand-mark"><x-icon name="drop"/></span><span><strong>ShowerQueue</strong></span></a><div class="topbar-right"><span class="sync-label"><i></i> Shared household</span><livewire:notification-link placement="header" key="notification-header"/><button class="icon-button mobile-only" type="button" data-theme-toggle aria-label="Change appearance"><x-icon name="sun"/></button></div></header>
        <main id="main" class="page-content" tabindex="-1">
            @if(request()->routeIs('profile', 'preferences', 'household', 'password.edit'))
                <a class="mobile-menu-back" href="{{ route('menu') }}"><x-icon name="chevron-left" size="18"/> Menu</a>
            @endif{{ $slot ?? '' }}@yield('content')</main>
        <footer class="page-footer"><span>ShowerQueue <span class="footer-dot">·</span> Private by design</span></footer>
    </div>
</div>
<nav class="mobile-nav" aria-label="Mobile navigation">
    <ul>
        @foreach([['schedule','home','Schedule'],['calendar','calendar','Calendar'],['swaps','swap','Swaps']] as [$route,$icon,$label])
            <li><a href="{{ route($route) }}" class="{{ request()->routeIs($route) ? 'active' : '' }}" @if(request()->routeIs($route)) aria-current="page" @endif><x-icon :name="$icon" size="23"/><span>{{ $label }}</span></a></li>
        @endforeach
        <li><livewire:notification-link placement="mobile" :active="request()->routeIs('inbox')" key="notification-mobile"/></li>
        <li><a href="{{ route('menu') }}" class="{{ request()->routeIs('menu') ? 'active' : '' }}" @if(request()->routeIs('menu')) aria-current="page" @endif><x-icon name="menu" size="23"/><span>Menu</span></a></li>
    </ul>
</nav>
@else
<main id="main" class="auth-page"><a href="/login" class="brand auth-brand"><span class="brand-mark"><x-icon name="drop" size="27"/></span><span>Shower<span class="brand-light">Queue</span></span></a>{{ $slot ?? '' }}@yield('content')</main>
@endif
@livewireScripts
</body>
</html>

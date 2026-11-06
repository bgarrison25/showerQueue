@props(['context' => 'sidebar', 'daily' => true])
<nav class="app-navigation" aria-label="{{ $context === 'sidebar' ? 'Main navigation' : 'All pages' }}">
    @if($daily)
    <section class="nav-group" aria-labelledby="{{ $context }}-daily">
        <h2 id="{{ $context }}-daily">Daily plans</h2>
        <ul>
            @foreach([['schedule','home','Schedule'],['calendar','calendar','Calendar'],['swaps','swap','Swaps']] as [$route,$icon,$label])
                <li><a href="{{ route($route) }}" class="{{ request()->routeIs($route) ? 'active' : '' }}" @if(request()->routeIs($route)) aria-current="page" @endif><x-icon :name="$icon"/><span>{{ $label }}</span></a></li>
            @endforeach
            <li><livewire:notification-link placement="sidebar" :active="request()->routeIs('inbox')" :key="'notification-'.$context"/></li>
        </ul>
    </section>
    @endif
    <section class="nav-group" aria-labelledby="{{ $context }}-household">
        <h2 id="{{ $context }}-household">Household</h2>
        <ul>
            <li><a href="{{ route('schedule') }}#household-members"><x-icon name="users"/><span>Members</span></a></li>
            @can('admin')<li><a href="{{ route('household') }}" class="{{ request()->routeIs('household') ? 'active' : '' }}" @if(request()->routeIs('household')) aria-current="page" @endif><x-icon name="settings"/><span>Manage household</span></a></li>@endcan
        </ul>
    </section>
    <section class="nav-group" aria-labelledby="{{ $context }}-account">
        <h2 id="{{ $context }}-account">Your account</h2>
        <ul>
            @foreach([['profile','user','Profile'],['preferences','settings','Settings']] as [$route,$icon,$label])
                <li><a href="{{ route($route) }}" class="{{ request()->routeIs($route) ? 'active' : '' }}" @if(request()->routeIs($route)) aria-current="page" @endif><x-icon :name="$icon"/><span>{{ $label }}</span></a></li>
            @endforeach
        </ul>
    </section>
</nav>

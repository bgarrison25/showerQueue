@props(['name', 'size' => 22])
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" {{ $attributes }}>
@switch($name)
@case('drop')<path d="M12 3s7 7.2 7 12a7 7 0 0 1-14 0c0-4.8 7-12 7-12Z"/><path d="M8 15a4 4 0 0 0 4 4"/>@break
@case('calendar')<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 11h18M8 15h2M14 15h2M8 18h2"/>@break
@case('swap')<path d="M4 7h16l-4-4M20 17H4l4 4M20 7l-4 4M4 17l4-4"/>@break
@case('bell')<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>@break
@case('home')<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1Z"/><path d="M9 21v-8h6v8"/>@break
@case('settings')<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3" fill="var(--surface)"/><circle cx="15" cy="17" r="3" fill="var(--surface)"/>@break
@case('clock')<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@break
@case('check')<path d="m5 12 4 4L19 6"/>@break
@case('plus')<path d="M12 5v14M5 12h14"/>@break
@case('chevron-left')<path d="m15 6-6 6 6 6"/>@break
@case('chevron-right')<path d="m9 6 6 6-6 6"/>@break
@case('sun')<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1 1M18 18l1 1M5 19l1-1M18 6l1-1"/>@break
@case('moon')<path d="M20 14a9 9 0 0 1-10-10 9 9 0 1 0 10 10Z"/>@break
@case('logout')<path d="M10 4H5v16h5M9 12h12l-4-4M21 12l-4 4"/>@break
@case('arrow')<path d="M4 12h16m-6-6 6 6-6 6"/>@break
@case('users')<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6M18 15a5 5 0 0 1 3 6"/>@break
@endswitch
</svg>

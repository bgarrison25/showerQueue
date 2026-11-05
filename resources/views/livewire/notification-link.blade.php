<a href="{{ route('inbox') }}"
   @unless(auth()->user()->must_change_password) wire:poll.15s.visible @endunless
   class="notification-link {{ $placement === 'header' ? 'icon-button' : ($active ? 'active' : '') }}"
   aria-label="{{ $placement === 'header' ? 'Notification inbox' : 'Inbox' }}{{ $unreadCount ? ', '.$unreadCount.' unread '.($unreadCount === 1 ? 'notification' : 'notifications') : ', no unread notifications' }}"
   @if($active && $placement !== 'header') aria-current="page" @endif>
    @if($placement === 'sidebar')
        <x-icon name="bell"/><span>Inbox</span>
        @if($unreadCount)<span class="nav-count" aria-hidden="true">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif
    @else
        <span class="notification-icon-wrap">
            <x-icon name="bell" :size="$placement === 'mobile' ? 21 : 22"/>
            @if($unreadCount)<span class="notification-badge" aria-hidden="true">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif
        </span>
        @if($placement === 'mobile')<span>Inbox</span>@endif
    @endif
</a>

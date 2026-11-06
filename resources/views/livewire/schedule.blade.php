<div class="schedule-page" wire:poll.10s.visible>
    <div class="offline-banner" wire:offline role="alert">You’re offline. The schedule may be out of date. Reconnect to make changes.</div>
    <div class="page-heading schedule-page-heading"><div><h1>Shower schedule</h1></div><span class="timezone-pill"><x-icon name="clock" size="15"/> {{ $settings->timezone }}</span></div>
    <x-feedback :notice="$notice"/>
    @if($calendarError)<div class="error-notice" role="alert">{{ $calendarError }} {{ $blocksBusy ? 'Reconnect to verify family availability before booking. Existing reservations remain safe.' : 'Family plans are temporarily unavailable.' }}</div>@endif
    <section class="shower-hero {{ $current ? 'is-occupied' : '' }}" aria-label="Current shower status">
        <div class="hero-main">
            <div class="hero-copy">
                <span class="card-label">CURRENT STATUS <span class="status-live"><i></i> Live</span></span>
                <h2>{{ $current ? ($current->status === 'running' ? 'Shower in use' : 'Shower reserved') : 'Shower is free' }}</h2>
                @if($current && $current->status === 'running')
                    <p>{{ $current->user->display_name }} is showering</p>
                    <div class="hero-timer" wire:key="timer-{{ $current->id }}-{{ now()->timestamp }}" x-data="showerTimer('{{ $current->ends_at->toIso8601String() }}','{{ now()->toIso8601String() }}')"><span class="timer" x-text="display">{{ (int) now()->diffInMinutes($current->ends_at) }}:00</span><span> remaining · ends {{ $current->ends_at->setTimezone($settings->timezone)->format('g:i A') }}</span></div>
                @else
                    <p>{{ $current ? 'Reserved for '.$current->user->display_name.' until '.$current->ends_at->setTimezone($settings->timezone)->format('g:i A') : 'Go ahead and reserve a time.' }}</p>
                    @if($current)<span class="small muted">Timer hasn’t started yet.</span>@endif
                @endif
            </div>
            <x-status-art/>
        </div>
        <div class="hero-details">
            <div><x-icon name="clock" size="20"/><span>Next available <small>{{ $day === $today ? 'Today' : \Carbon\CarbonImmutable::parse($day)->format('M j') }}</small></span><strong>{{ $nextAvailable ? $nextAvailable['local']->format('g:i A') : 'No open times' }}</strong></div>
            <span class="hero-duration">{{ $settings->slot_minutes }} min per shower</span>
        </div>
    </section>
    @if($mine)<section class="mobile-your-time"><div><span class="card-label">YOUR TIME · {{ \Carbon\CarbonImmutable::parse($day)->format('M j') }}</span><strong>{{ $mine->starts_at->setTimezone($settings->timezone)->format('g:i A') }} <small>· {{ $mine->status === 'running' ? 'Showering' : 'Reserved for you' }}</small></strong></div>@if($mine->status === 'running')<button class="small-button" wire:click="finish({{ $mine->id }})" wire:offline.attr="disabled">Finished <x-icon name="check" size="15"/></button>@elseif($mine->starts_at->lte(now()))<button class="small-button" wire:click="start({{ $mine->id }})" wire:offline.attr="disabled">Start shower</button>@else<button class="small-button" wire:click="move({{ $mine->id }})" wire:offline.attr="disabled">Move time <x-icon name="arrow" size="15"/></button>@endif</section>@endif
    <div class="schedule-layout">
    <section class="calendar-panel panel">
        <div class="calendar-heading"><div><h2>{{ $day === $today ? 'Today’s schedule' : \Carbon\CarbonImmutable::parse($day)->format('l') . '’s schedule' }}</h2><p class="muted small">{{ \Carbon\CarbonImmutable::parse($day)->format('l, F j, Y') }}</p></div><div class="segmented" aria-label="Calendar view"><button wire:click="$set('viewMode','day')" class="{{ $viewMode === 'day' ? 'selected' : '' }}" aria-pressed="{{ $viewMode === 'day' ? 'true' : 'false' }}">Day</button><button wire:click="$set('viewMode','week')" class="{{ $viewMode === 'week' ? 'selected' : '' }}" aria-pressed="{{ $viewMode === 'week' ? 'true' : 'false' }}">Week</button></div></div>
        <details class="schedule-options" wire:key="schedule-options" wire:ignore.self>
            <summary><span><x-icon name="settings" size="18"/> Dates &amp; filters</span><span class="options-current">{{ ucfirst($period) }} times <x-icon name="chevron-right" size="16"/></span></summary>
        <div class="date-toolbar"><div class="date-controls"><button class="icon-button bordered" wire:click="navigate({{ $viewMode === 'week' ? -7 : -1 }})" aria-label="Previous {{ $viewMode }}"><x-icon name="chevron-left" size="18"/></button><button class="text-button today-button" wire:click="today">Today</button><button class="icon-button bordered" wire:click="navigate({{ $viewMode === 'week' ? 7 : 1 }})" aria-label="Next {{ $viewMode }}"><x-icon name="chevron-right" size="18"/></button><label class="sr-only" for="schedule-date">Choose a date</label><input id="schedule-date" type="date" wire:model.live="day" min="{{ \Carbon\CarbonImmutable::parse($today)->subDays(7)->toDateString() }}" max="{{ \Carbon\CarbonImmutable::parse($today)->addDays(90)->toDateString() }}" class="date-input"></div><span class="slot-label"><span class="dot"></span>{{ $settings->slot_minutes }} min slots</span></div>
            @if($viewMode === 'day')
        <div class="schedule-filters" aria-label="Filter time of day">@foreach(['all'=>'All times','morning'=>'Morning','afternoon'=>'Afternoon','evening'=>'Evening'] as $value=>$label)<button wire:click="$set('period','{{ $value }}')" class="{{ $period === $value ? 'selected' : '' }}" aria-pressed="{{ $period === $value ? 'true' : 'false' }}">{{ $label }}</button>@endforeach</div>
            @endif
        </details>
        @if($viewMode === 'day')
        @if(auth()->user()->is_admin)<div class="admin-booking"><label>Book for<select wire:model.live="ownerId">@foreach($members as $member)<option value="{{ $member->id }}">{{ $member->display_name }}{{ $member->id === auth()->id() ? ' (you)' : '' }}</option>@endforeach</select></label><label class="checkbox-label"><input type="checkbox" wire:model.live="override"> Parent override <span title="Allows an extra daily booking and bypasses attendance, fairness, and family-event blocks. Two shower reservations can never overlap." class="help-dot">?</span></label></div>@endif
        @endif
        <div class="week-strip {{ $viewMode === 'day' ? 'day-view-strip' : '' }}" aria-label="Days of the week">@foreach($week as $weekDay)<button wire:key="day-{{ $weekDay['date']->toDateString() }}" wire:click="selectDay('{{ $weekDay['date']->toDateString() }}')" class="{{ $day === $weekDay['date']->toDateString() ? 'selected' : '' }} {{ $today === $weekDay['date']->toDateString() ? 'is-today' : '' }}"><span>{{ $weekDay['date']->format('D') }}</span><strong>{{ $weekDay['date']->format('j') }}</strong><i class="{{ $weekDay['bookings']->isNotEmpty() ? 'has-booking' : '' }}"></i></button>@endforeach</div>
        @if($viewMode === 'week')
        <div class="week-agenda">@foreach($week as $weekDay)<div class="week-agenda-day"><button class="text-button" wire:click="selectDay('{{ $weekDay['date']->toDateString() }}')"><strong>{{ $weekDay['date']->format('D, M j') }}</strong><x-icon name="chevron-right" size="17"/></button>@forelse($weekDay['bookings'] as $r)<div class="week-booking"><x-avatar :user="$r->user" class="mini"/><span>{{ $r->user->display_name }}</span><time>{{ $r->starts_at->setTimezone($settings->timezone)->format('g:i A T') }}</time></div>@empty<p class="small muted">An open day. Choose a time that works.</p>@endforelse</div>@endforeach</div>
        @else
        @if($movingId)<div class="moving-banner"><div><strong>Choose a new time below.</strong><span>Your original reservation stays safe until the move succeeds.</span></div><button class="text-button" wire:click="$set('movingId',null)">Keep original</button></div>@endif
        <div class="schedule-list" aria-label="Shower time slots">
        @forelse($rows as $slot)
        @php($r = $slot['reservation'])
        @php($isOwn = $r && $r->user_id === auth()->id())
        @php($past = $slot['end']->isPast())
        <div wire:key="slot-{{ $slot['start']->timestamp }}-{{ $r?->id ?? 'free' }}-{{ $r?->revision ?? 0 }}" class="slot-row {{ $past ? 'past-slot' : '' }} {{ $r ? 'reserved-row' : '' }}">
            <div class="slot-time"><strong>{{ $slot['local']->format('g:i') }}</strong><span>{{ $slot['local']->format('A') }}</span><small>{{ $slot['local']->format('T') }}</small></div>
            @if($r)<div class="reservation-block color-{{ $r->user->color }} {{ $isOwn ? 'own-reservation' : '' }}"><x-avatar :user="$r->user"/><div class="reservation-copy"><strong>{{ $r->user->display_name }} @if($isOwn)<span class="you-label">you</span>@endif</strong><span>{{ $r->starts_at->setTimezone($settings->timezone)->format('g:i') }}–{{ $r->ends_at->setTimezone($settings->timezone)->format('g:i A') }} · {{ $r->status === 'finished' ? 'Finished' : ($past ? 'Past reservation' : ($r->status === 'running' ? 'Showering' : ($r->recurring_pattern_id ? 'Recurring' : 'Reserved'))) }}</span></div>
            <div class="reservation-end"><span class="slot-state {{ $isOwn ? 'state-yours' : 'state-taken' }}"><i></i>{{ $r->status === 'finished' ? 'Finished' : ($past ? 'Past' : ($r->status === 'running' ? 'In use' : ($isOwn ? 'Your time' : 'Taken'))) }}</span><div class="slot-actions">
                @if($r->status === 'running' && ($isOwn || auth()->user()->is_admin))<button class="small-button" wire:click="finish({{ $r->id }})" wire:offline.attr="disabled">Finished <x-icon name="check" size="15"/></button>
                @elseif(!$past && $r->status === 'booked')
                    @if(($isOwn || auth()->user()->is_admin) && $r->starts_at->lte(now()))<button class="small-button" wire:click="start({{ $r->id }})" wire:offline.attr="disabled">Start shower</button>
                    @elseif($isOwn || auth()->user()->is_admin)<button class="small-button" wire:click="move({{ $r->id }})" wire:offline.attr="disabled">Move</button><button class="icon-button" wire:click="cancel({{ $r->id }})" wire:offline.attr="disabled" aria-label="Cancel {{ $r->user->display_name }}’s {{ $r->starts_at->setTimezone($settings->timezone)->format('g:i A') }} reservation" title="Cancel reservation"><span aria-hidden="true">×</span></button>@if(!$isOwn && $mine && $mine->starts_at->isFuture())@include('livewire.partials.swap-button', ['compact' => true])@endif
                    @elseif($mine && $mine->starts_at->isFuture() && $r->starts_at->isFuture())@include('livewire.partials.swap-button', ['compact' => false])@endif
                @endif
            </div></div></div>
            @elseif(($slot['blocked'] || ($calendarError && $blocksBusy)) && !(auth()->user()->is_admin && $override))<div class="family-blocked-slot"><x-icon name="calendar" size="18"/><div><strong>{{ $slot['blocked'] ? 'Family time' : 'Availability unavailable' }}</strong><small>{{ $slot['blocked'] ? strip_tags($slot['familyConflicts'][0]['summary'] ?? 'Busy family event') : 'Reconnect to check this time' }}</small></div><a class="text-button" href="{{ route('calendar', ['day' => $day]) }}">Calendar</a></div>
            @else<div class="open-slot">
                <span class="avatar open-avatar" aria-hidden="true"><x-icon name="drop" size="23"/></span>
                <div class="open-slot-copy"><strong>{{ $slot['start']->isPast() ? 'Time has passed' : ($slot['blocked'] ? 'Family time' : 'Open time') }}</strong><span>{{ $slot['local']->format('g:i') }}–{{ $slot['end']->setTimezone($settings->timezone)->format('g:i A') }}</span>@if($slot['blocked'])<small>Parent override</small>@endif</div>
                <div class="open-slot-end">
                    <span class="slot-state {{ $slot['start']->isPast() ? 'state-taken' : 'state-available' }}"><i></i>{{ $slot['start']->isPast() ? 'Past' : 'Available' }}</span>
                    <button class="reserve-button" wire:click="book('{{ $slot['start']->toIso8601String() }}')" wire:offline.attr="disabled" wire:loading.attr="disabled" @disabled($slot['start']->isPast()) aria-label="{{ $movingId ? 'Move to' : 'Reserve' }} {{ $slot['local']->format('g:i A T') }}">@unless($slot['start']->isPast())<x-icon name="plus" size="17"/>@endunless{{ $slot['start']->isPast() ? 'Closed' : ($movingId ? 'Move here' : 'Reserve') }}</button>
                </div>
            </div>@endif
        </div>
        @if($r && $pendingSwaps->has($r->id))<p class="swap-feedback" role="status">Swap requested. Waiting for {{ $r->user->display_name }} to accept. <a class="text-button" href="{{ route('swaps') }}">View request</a></p>@endif
        @if($r && $swapTargetId === $r->id)@error('booking')<p class="swap-feedback error-notice" role="alert">{{ $message }}</p>@enderror@endif
        @if($r && $slot['familyConflicts'] && !$past)<p class="family-overlap-note">Family event overlap: {{ strip_tags($slot['familyConflicts'][0]['summary'] ?? 'Busy family event') }}. <a class="text-button" href="{{ route('calendar', ['day' => $day]) }}">Review calendar</a></p>@endif
        @empty<div class="empty-state"><x-icon name="sun" size="34"/><h3>No upcoming times</h3><p>No upcoming times in this view. Try another filter or day.</p><button class="button button-secondary" wire:click="navigate(1)">See tomorrow <x-icon name="arrow" size="17"/></button></div>@endforelse
        </div>
        @if($totalSlots > 12)<button class="show-earlier" wire:click="$toggle('expandedSlots')">{{ $expandedSlots ? 'Show fewer times' : 'Show all '.$totalSlots.' times' }} <span aria-hidden="true">↓</span></button>@endif
        @if($day === $today)<button class="show-earlier" wire:click="$toggle('showPast')">{{ $showPast ? 'Hide earlier times' : 'Show earlier times' }}</button>@endif
        <div class="calendar-footnote"><x-icon name="check" size="15"/> Every reservation updates automatically for everyone.</div>
        @endif
    </section>
    <aside class="schedule-aside">
        <section class="panel your-turn"><div class="aside-title"><x-icon name="calendar" size="19"/><h2>Your time</h2></div>
        @if($mine)<div class="your-time">{{ $mine->starts_at->setTimezone($settings->timezone)->format('g:i') }}<span>{{ $mine->starts_at->setTimezone($settings->timezone)->format('A') }}</span></div><p class="muted small">{{ \Carbon\CarbonImmutable::parse($day)->format('D, M j') }} · {{ (int) $mine->starts_at->diffInMinutes($mine->ends_at) }} minutes to yourself</p><span class="booking-confirmed"><x-icon name="check" size="15"/> You’re on the schedule</span><button class="button button-secondary button-full" wire:click="move({{ $mine->id }})" wire:offline.attr="disabled" @disabled($mine->starts_at->isPast())>Choose another time</button><a class="text-button centered" href="{{ route('swaps') }}">Manage your swaps <x-icon name="arrow" size="15"/></a>
        @else<div class="empty-turn-icon"><x-icon name="drop" size="30"/></div><h3>No reservation yet</h3><p class="muted small">Tap an available time to reserve your shower for {{ $day === $today ? 'today' : 'this day' }}.</p><span class="rule-note">One reservation per person, per day.</span>@endif
        </section>
        <section class="panel attendance-card" id="household-members" tabindex="-1"><div class="aside-title"><x-icon name="home" size="19"/><h2>Who’s home?</h2><span class="count-pill">{{ $attendance->filter()->count() }}</span></div><p class="muted small">{{ $day === $today ? 'Today’s' : \Carbon\CarbonImmutable::parse($day)->format('D, M j') }} household</p><ul class="member-list">@foreach($members as $member)<li><x-avatar :user="$member"/><strong>{{ $member->display_name }}@if($member->id === auth()->id())<span class="you-label">you</span>@endif</strong><span class="attendance-status {{ $attendance[$member->id] ? 'home' : '' }}"><i></i>{{ $attendance[$member->id] ? 'Home' : 'Away' }}</span></li>@endforeach</ul><p class="attendance-note">Based on your household’s schedule.@can('admin') <a href="{{ route('household') }}">Manage attendance <x-icon name="arrow" size="13"/></a>@endcan</p></section>
        <div class="gentle-note"><x-icon name="sun" size="22"/><div><strong>Changing your time</strong><p>Need a different time? Move your booking or ask for a swap. Your spot stays yours until the change is confirmed.</p></div></div>
    </aside>
    </div>
</div>

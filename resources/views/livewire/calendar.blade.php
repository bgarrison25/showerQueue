<div wire:poll.45s.visible x-data
     x-on:calendar-day-selected.window="$nextTick(() => { const events = $refs.dayEvents; const bounds = events.getBoundingClientRect(); if (bounds.top < 80 || bounds.top > window.innerHeight - 180) events.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' }); $refs.dayHeading.focus({ preventScroll: true }); })"
     x-on:calendar-editor-opened.window="$nextTick(() => { $refs.eventEditor.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' }); $refs.eventTitle.focus({ preventScroll: true }); })">
    <div class="offline-banner" wire:offline role="alert">You’re offline. Reconnect to view updates or change family events.</div>
    <div class="page-heading family-page-heading">
        <div><h1>Calendar</h1><p class="muted">Shower reservations and family events.</p></div>
        @can('admin')<button class="button button-primary" wire:click="newEvent" wire:offline.attr="disabled" @disabled(!$google->configured())><x-icon name="plus" size="18"/> Family event</button>@endcan
    </div>
    <x-feedback :notice="$notice"/>
    @if($calendarError)<div class="error-notice" role="alert">{{ $calendarError }} Shower bookings are still shown. Family events are unavailable until the connection returns.</div>@endif
    @unless($google->configured())
        <section class="panel calendar-connection"><div class="aside-title"><x-icon name="calendar"/><h2>Bring your family plans together</h2></div><p class="muted">Your shower bookings appear below. Connect your shared Google Calendar to see family events, add plans, and keep busy times clear.</p>
            @can('admin')<details><summary>Google Calendar connection setup</summary><ol><li>Enable the Google Calendar API in a Google Cloud project and create a service account with a JSON key.</li><li>Share your family calendar with the key’s <code>client_email</code>, allowing changes and access to event details.</li><li>In Laravel Cloud, set <code>GOOGLE_CALENDAR_ID</code> and <code>GOOGLE_CALENDAR_CREDENTIALS_BASE64</code> (the complete JSON key encoded as Base64). Keep the key in environment variables.</li><li>Deploy with migrations and enable the scheduler. Then use “Sync now” below.</li></ol><p class="small muted">Detailed instructions are in the project’s docs/GOOGLE_CALENDAR.md.</p></details>@else<p class="small muted">Ask Bill or Aleasha to connect the family calendar.</p>@endcan
        </section>
    @endunless
    <div class="family-calendar-layout {{ $formOpen && auth()->user()->is_admin ? 'has-event-editor' : '' }}">
        <section class="panel family-month-panel" aria-label="Month calendar">
            <div class="calendar-heading"><h2>{{ $selected->format('F Y') }}</h2><div class="date-controls"><button class="icon-button bordered" wire:click="navigate(-1)" aria-label="Previous month"><x-icon name="chevron-left" size="18"/></button><button class="text-button" wire:click="today">Today</button><button class="icon-button bordered" wire:click="navigate(1)" aria-label="Next month"><x-icon name="chevron-right" size="18"/></button></div></div>
            <div class="family-calendar-legend"><span><i class="family-event-dot"></i> Family plans</span><span><i class="shower-event-dot"></i> Shower times</span></div>
            <div class="family-weekdays" aria-hidden="true">@foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $weekday)<span>{{ $weekday }}</span>@endforeach</div>
            <div class="family-month-grid">
                @foreach($days as $entry)
                    <button type="button" wire:key="calendar-day-{{ $entry['date']->toDateString() }}" wire:click="selectDay('{{ $entry['date']->toDateString() }}')" class="family-day {{ $entry['date']->month !== $selected->month ? 'outside-month' : '' }} {{ $entry['date']->toDateString() === $day ? 'selected' : '' }} {{ $entry['date']->isToday() ? 'is-today' : '' }}" aria-pressed="{{ $entry['date']->toDateString() === $day ? 'true' : 'false' }}" aria-label="{{ $entry['date']->format('l, F j') }}: {{ $entry['familyCount'] }} family events, {{ $entry['showerCount'] }} showers">
                        <strong>{{ $entry['date']->format('j') }}</strong>
                        <div class="family-day-preview">@foreach($entry['items']->take(2) as $item)<span class="{{ $item['kind'] === 'shower' ? 'shower-preview' : 'family-preview' }}">{{ $item['title'] }}</span>@endforeach @if($entry['items']->count() > 2)<small>+{{ $entry['items']->count()-2 }} more</small>@endif</div>
                        <span class="family-day-dots">@if($entry['familyCount'])<i class="family-event-dot"></i>@endif @if($entry['showerCount'])<i class="shower-event-dot"></i>@endif</span>
                    </button>
                @endforeach
            </div>
            @include('livewire.partials.calendar-agenda')
            @if($google->configured())<div class="family-calendar-sync"><a class="text-button" href="{{ $google->calendarUrl() }}" target="_blank" rel="noopener noreferrer">Open Google Calendar <x-icon name="arrow" size="16"/></a><button class="text-button" wire:click="refreshCalendar" wire:offline.attr="disabled" wire:loading.attr="disabled">Refresh plans</button>@can('admin')<button class="text-button" wire:click="syncNow" wire:offline.attr="disabled" wire:loading.attr="disabled">Sync now</button>@endcan</div><p class="small muted">@if($lastSync)Showers last synced {{ \Carbon\CarbonImmutable::parse($lastSync)->diffForHumans() }}.@else Shower sync is waiting for its first run.@endif Family plans refresh about every minute.</p>@endif
        </section>
        @if($formOpen && auth()->user()->is_admin)
        <aside class="family-agenda-column">
                <section class="panel family-event-form" id="family-event-form" x-ref="eventEditor"><div class="section-heading"><h2>{{ $eventId ? 'Edit family event' : 'A new family plan' }}</h2><button class="icon-button" wire:click="$set('formOpen',false)" aria-label="Close event editor">×</button></div>
                    @if($recurring)<p class="small muted">Changes apply to this occurrence only. Manage the full series in Google Calendar.</p>@endif
                    <form wire:submit="saveEvent" class="form-stack">
                        <label>What’s happening?<input type="text" wire:model="title" x-ref="eventTitle" maxlength="200" required placeholder="Dinner, practice, a day out…"></label>
                        <label class="checkbox-label"><input type="checkbox" wire:model.live="allDay"> All day</label>
                        <label>Starts<input type="{{ $allDay ? 'date' : 'datetime-local' }}" wire:model="startsAt" required></label>
                        <label>{{ $allDay ? 'Last day (included)' : 'Ends' }}<input type="{{ $allDay ? 'date' : 'datetime-local' }}" wire:model="endsAt" required></label>
                        <label>Place <span class="muted">(optional)</span><input type="text" wire:model="location" maxlength="300" placeholder="Where to meet"></label>
                        <label>Notes <span class="muted">(optional)</span><textarea wire:model="description" rows="3" maxlength="4000"></textarea></label>
                        <label class="checkbox-label"><input type="checkbox" wire:model="busy"> Mark as Busy</label>
                        <p class="small muted">{{ $allDay ? 'All-day events never block showers.' : 'Busy events block new showers for everyone. Free events are just a heads-up.' }} Existing shower bookings stay in place; their owners receive overlap alerts.</p>
                        <button class="button button-primary button-full" wire:loading.attr="disabled" wire:offline.attr="disabled" @disabled((bool)$calendarError)>Save family event</button>
                        @if($eventId)<button type="button" class="text-button danger-text" wire:click="deleteEvent" wire:confirm="Remove this family event from the shared Google Calendar?" wire:loading.attr="disabled" wire:offline.attr="disabled">Delete {{ $recurring ? 'this occurrence' : 'event' }}</button>@endif
                    </form>
                </section>
        </aside>
        @endif
    </div>
</div>

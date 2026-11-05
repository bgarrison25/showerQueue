<section class="family-agenda selected-day-agenda" id="selected-day-events" x-ref="dayEvents" aria-labelledby="selected-day-heading">
    <div class="selected-day-heading">
        <div><span class="eyebrow">{{ $selected->isToday() ? 'TODAY’S EVENTS' : 'SELECTED DAY' }}</span><h2 id="selected-day-heading" x-ref="dayHeading" tabindex="-1" aria-live="polite">{{ $selected->format('l, F j') }}</h2></div>
        <span class="badge">{{ $agenda->count() }} {{ $agenda->count() === 1 ? 'event' : 'events' }}</span>
    </div>
    <div class="selected-day-event-list" wire:key="selected-day-{{ $day }}">
        @forelse($agenda as $item)
            <article class="family-agenda-event {{ $item['kind'] === 'shower' ? 'shower-agenda-event' : '' }}" wire:key="agenda-{{ $item['key'] }}">
                <span class="family-agenda-icon"><x-icon :name="$item['kind'] === 'shower' ? 'drop' : 'calendar'" size="19"/></span>
                <div class="grow"><strong>{{ $item['title'] }}</strong><p class="small muted">{{ $item['allDay'] ? 'All day' : $item['start']->format('g:i A').' – '.$item['end']->format($item['end']->toDateString() !== $item['start']->toDateString() ? 'M j, g:i A T' : 'g:i A T') }}</p>
                    @if($item['location'])<p class="small muted">{{ $item['location'] }}</p>@endif
                    @if($item['description'])<p class="small family-event-description">{{ $item['description'] }}</p>@endif
                    @if($item['blocks'])<span class="badge">Keeps shower times clear</span>@endif
                    @if($item['overlaps'])<p class="family-overlap-note">Overlaps {{ implode(', ', $item['overlaps']) }}. Your shower stays booked.</p>@endif
                    @if($item['kind'] === 'shower')<a class="text-button" href="{{ route('schedule', ['day' => $item['start']->toDateString()]) }}">View shower schedule <x-icon name="arrow" size="14"/></a>
                    @elseif($item['editable'])@can('admin')<button class="text-button" wire:click="editEvent('{{ $item['id'] }}')" wire:offline.attr="disabled">Edit event <x-icon name="arrow" size="14"/></button>@endcan @endif
                </div>
            </article>
        @empty
            <div class="empty-state"><x-icon name="sun" size="26"/><h3>Room to make plans.</h3><p>{{ $calendarError ? 'Family plans are temporarily unavailable.' : 'Nothing on the calendar for this day.' }}</p></div>
        @endforelse
    </div>
</section>

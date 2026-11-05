<?php

namespace App\Services;

use App\Exceptions\CalendarException;
use App\Models\HouseholdSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class FamilyCalendar
{
    public function __construct(private GoogleCalendar $google) {}

    public function editable(User $actor, string $id): array
    {
        Gate::forUser($actor->fresh())->authorize('admin');
        $event = $this->google->get($id);
        if (!$event) throw new CalendarException('This event was removed. Refresh the calendar.');
        if ($this->google->isShower($event)) throw ValidationException::withMessages(['calendar' => 'Change shower reservations from the shower schedule.']);
        if (($event['eventType'] ?? 'default') !== 'default' || !empty($event['locked'])) throw new CalendarException('Please manage this special event in Google Calendar.');
        return $event;
    }

    public function save(User $actor, array $data, string $creationId, ?string $id = null, ?string $etag = null): array
    {
        Gate::forUser($actor->fresh())->authorize('admin');
        $data = Validator::make($data, [
            'title' => 'required|string|max:200', 'location' => 'nullable|string|max:300',
            'description' => 'nullable|string|max:4000', 'allDay' => 'required|boolean', 'busy' => 'required|boolean',
            'startsAt' => 'required|date_format:'.(!empty($data['allDay']) ? 'Y-m-d' : 'Y-m-d\TH:i'),
            'endsAt' => 'required|date_format:'.(!empty($data['allDay']) ? 'Y-m-d' : 'Y-m-d\TH:i'),
        ])->validate();
        $timezone = HouseholdSetting::current()->timezone;
        $start = CarbonImmutable::parse($data['startsAt'], $timezone);
        $end = CarbonImmutable::parse($data['endsAt'], $timezone);
        $format = $data['allDay'] ? 'Y-m-d' : 'Y-m-d\TH:i';
        if ($start->format($format) !== $data['startsAt'] || $end->format($format) !== $data['endsAt']) {
            throw ValidationException::withMessages(['startsAt' => 'That local time does not exist because the clocks change. Choose another time.']);
        }
        if ($data['allDay']) $end = $end->addDay(); // The form uses an inclusive last day.
        if (!$end->gt($start) || $end->gt($start->addYear())) throw ValidationException::withMessages(['endsAt' => 'Choose an end after the start, within one year.']);
        $payload = [
            'summary' => trim($data['title']), 'description' => $data['description'] ?? '', 'location' => $data['location'] ?? '',
            'start' => $data['allDay'] ? ['date' => $start->toDateString(), 'dateTime' => null, 'timeZone' => null] : ['date' => null, 'dateTime' => $start->toRfc3339String(), 'timeZone' => $timezone],
            'end' => $data['allDay'] ? ['date' => $end->toDateString(), 'dateTime' => null, 'timeZone' => null] : ['date' => null, 'dateTime' => $end->toRfc3339String(), 'timeZone' => $timezone],
            'transparency' => $data['busy'] ? 'opaque' : 'transparent',
        ];
        if ($id) {
            $event = $this->editable($actor, $id);
            if (!$etag || ($event['etag'] ?? '') !== $etag) throw new CalendarException('Someone changed this event. Reopen it before saving so their changes are preserved.');
            $result = $this->google->update($id, $payload, $etag);
        } else {
            if (!preg_match('/^[a-f0-9]{32}$/', $creationId)) throw new CalendarException('Start a new event and try again.');
            $result = $this->google->insert($payload + ['id' => $creationId, 'extendedProperties' => ['private' => ['showerqueue_family_creation' => $creationId]]]);
        }
        $this->google->invalidate();
        return $result;
    }

    public function delete(User $actor, string $id, string $etag): void
    {
        $event = $this->editable($actor, $id);
        if (!$etag || ($event['etag'] ?? '') !== $etag) throw new CalendarException('Someone changed this event. Reopen it before deleting.');
        $this->google->delete($id, $etag);
        $this->google->invalidate();
    }
}

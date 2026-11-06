<?php

namespace App\Services;

use App\Models\Reservation;

class NotificationDestination
{
    private array $reservationDays = [];

    public function url(array $data): string
    {
        $kind = $data['kind'] ?? '';
        $tag = is_string($data['tag'] ?? null) ? $data['tag'] : '';
        if (in_array($kind, ['swap_requested', 'swap_resolved'], true)) {
            return '/swaps'.(preg_match('/^swap-[a-z]+:(\d+)$/', $tag, $match) ? '#swap-'.$match[1] : '');
        }

        $calendar = in_array($kind, ['family_event', 'family_overlap'], true);
        $path = $calendar ? '/calendar' : '/';
        $day = $data['day'] ?? null;
        if (!$this->validDay($day)) {
            $day = null;
            // Older notifications contain a reservation ID in their delivery key.
            if (preg_match('/^(?:booked|changed|cancelled|soon|next|family-overlap):(\d+)(?::.*)?$/', $tag, $match)) {
                $id = $match[1];
                if (!array_key_exists($id, $this->reservationDays)) {
                    $this->reservationDays[$id] = Reservation::whereKey($id)->value('day');
                }
                $day = $this->reservationDays[$id];
            }
        }

        // Build internal destinations rather than trusting a stored URL.
        return $path.($this->validDay($day) ? '?day='.$day.($calendar ? '#selected-day-events' : '') : '');
    }

    private function validDay(mixed $day): bool
    {
        return is_string($day) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}

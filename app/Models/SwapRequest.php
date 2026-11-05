<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SwapRequest extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array { return ['expires_at' => 'immutable_datetime']; }
    public function fromReservation() { return $this->belongsTo(Reservation::class, 'from_reservation_id'); }
    public function toReservation() { return $this->belongsTo(Reservation::class, 'to_reservation_id'); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function recipient() { return $this->belongsTo(User::class, 'recipient_id'); }
}

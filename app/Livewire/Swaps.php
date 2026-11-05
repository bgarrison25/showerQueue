<?php

namespace App\Livewire;

use App\Models\SwapRequest;
use App\Services\BookingService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Swaps extends Component
{
    public string $notice = '';
    public function respond(int $id, bool $accept): void
    {
        $this->resetErrorBag();
        try { app(BookingService::class)->respondSwap(auth()->user(), $id, $accept); $this->notice = $accept ? 'Swap accepted. Both reservations have been updated.' : 'Swap declined. Original times are unchanged.'; $this->dispatch('notifications-updated'); }
        catch (ValidationException $e) { $this->addError('swap', collect($e->errors())->flatten()->first()); }
    }
    public function render()
    {
        $swaps = SwapRequest::with(['fromReservation.user', 'toReservation.user', 'requester', 'recipient'])->when(!auth()->user()->is_admin, fn ($q) => $q->where(fn ($q) => $q->where('requested_by', auth()->id())->orWhere('recipient_id', auth()->id())))->latest()->limit(50)->get();
        return view('livewire.swaps', compact('swaps'))->layout('layouts.app', ['title' => 'Swaps']);
    }
}

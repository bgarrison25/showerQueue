@if($pendingSwaps->has($r->id))
    <a class="{{ $compact ? 'icon-button' : 'small-button' }}" href="{{ route('swaps') }}" aria-label="View swap request with {{ $r->user->display_name }}" title="Swap requested"><x-icon name="check" size="15"/>{{ $compact ? '' : ' Requested' }}</a>
@else
    <button type="button" class="{{ $compact ? 'icon-button' : 'small-button' }}" wire:click="requestSwap({{ $mine->id }},{{ $r->id }})" wire:offline.attr="disabled" wire:loading.attr="disabled" wire:target="requestSwap" aria-label="Request a swap with {{ $r->user->display_name }}" title="Request swap">
        <span wire:loading.remove wire:target="requestSwap({{ $mine->id }},{{ $r->id }})"><x-icon name="swap" size="15"/>{{ $compact ? '' : ' Swap' }}</span>
        <span wire:loading wire:target="requestSwap({{ $mine->id }},{{ $r->id }})" role="status">{{ $compact ? '…' : 'Sending…' }}</span>
    </button>
@endif

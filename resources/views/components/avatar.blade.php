@props(['user'])
<span {{ $attributes->class(['avatar', 'color-'.$user->color]) }} aria-hidden="true">
    <span>{{ mb_substr($user->profileFirstName(), 0, 1) }}</span>
    @if($user->profile_photo_path)
        <img src="{{ $user->profilePhotoUrl() }}" alt="" loading="lazy" decoding="async" onerror="this.hidden = true" onload="this.hidden = false">
    @endif
</span>

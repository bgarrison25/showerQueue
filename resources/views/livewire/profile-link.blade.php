<a href="{{ route('profile') }}" class="profile-identity" aria-label="Your profile: {{ $user->display_name }}">
    <x-avatar :user="$user"/>
    <span><strong>{{ $user->display_name }}</strong><small>{{ $user->is_admin ? 'Parent account' : 'Household member' }}</small></span>
</a>

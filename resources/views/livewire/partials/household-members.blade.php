<section class="panel household-members-panel" aria-labelledby="household-members-heading">
    <div class="section-heading"><div><h2 id="household-members-heading">Members</h2><p class="small muted">Edit the names and colors shown throughout your household.</p></div><span class="badge">{{ $members->count() }} members</span></div>
    <div class="member-management-grid">
        @foreach($members as $member)
        <article class="member-management-card" wire:key="member-editor-{{ $member->id }}">
            <div class="member-management-summary"><x-avatar :user="$member"/><div><h3>{{ $member->display_name }}</h3><p class="small muted">{{ $member->is_admin ? 'Parent' : 'Member' }} · {{ $member->is_active ? 'Active' : 'Paused' }}</p></div></div>
            <p class="small muted">Sign-in name: <strong>{{ $member->name }}</strong></p>
            @if($member->must_change_password)<p class="small">Password setup needed</p>@endif
            @if($editingMemberId === $member->id)
            <form wire:submit="saveMember" class="form-stack member-profile-form">
                <div class="form-row"><label>First name<input wire:model="memberProfile.first_name" required maxlength="60"></label><label>Last name (optional)<input wire:model="memberProfile.last_name" maxlength="80"></label></div>
                <label>Nickname (optional)<input wire:model="memberProfile.nickname" maxlength="30"></label>
                <label>Member color<select wire:model="memberProfile.color">@foreach(['teal'=>'Teal','rose'=>'Rose','blue'=>'Blue','amber'=>'Amber','violet'=>'Violet','pink'=>'Pink'] as $color=>$label)<option value="{{ $color }}">{{ $label }}</option>@endforeach</select></label>
                <p class="small muted">Nicknames appear between first and last names. Changing these details does not change their sign-in name.</p>
                @foreach($errors->getMessages() as $field=>$messages)@if(str_starts_with($field, 'memberProfile.'))<p class="error-notice" role="alert">{{ $messages[0] }}</p>@endif @endforeach
                <div class="button-row"><button class="button button-primary" wire:loading.attr="disabled" wire:offline.attr="disabled">Save member</button><button type="button" class="button button-secondary" wire:click="cancelMemberEdit">Cancel</button></div>
            </form>
            @else
            <button class="text-button" wire:click="editMember({{ $member->id }})" aria-label="Edit profile for {{ $member->display_name }}">Edit profile <x-icon name="arrow" size="17"/></button>
            @endif
        </article>
        @endforeach
    </div>
</section>

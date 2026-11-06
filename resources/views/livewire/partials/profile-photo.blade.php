<section class="panel profile-photo-panel" aria-labelledby="profile-photo-heading"
    x-data="profilePhotoPicker()" x-on:profile-photo-saved.window="clearPreview(); error = ''; progress = 0" x-on:online.window="online = true" x-on:offline.window="online = false">
    <div class="aside-title"><x-icon name="camera"/><h2 id="profile-photo-heading">Your profile photo</h2></div>
    <p class="muted small">Make your time easy to spot. Your photo appears in the schedule, household, and swaps.</p>
    <div class="photo-editor">
        <div class="photo-preview">
            <x-avatar :user="auth()->user()" class="profile-photo-large"/>
            <template x-if="previewUrl"><img :src="previewUrl" alt="New profile photo preview" class="photo-preview-image"></template>
        </div>
        <div class="photo-editor-copy"><strong>{{ auth()->user()->display_name }}</strong><p class="muted small" x-text="previewUrl ? 'Preview your photo before saving.' : 'Visible only to your household.'"></p></div>
    </div>
    <div class="photo-picker-actions">
        <label class="button button-secondary photo-picker"><x-icon name="camera" size="19"/> Take photo
            <input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" capture="user" aria-label="Take a profile photo" aria-describedby="photo-help" :disabled="busy || !online" wire:loading.attr="disabled" wire:target="savePhoto,removePhoto" x-on:change="choose($event, $wire)">
        </label>
        <label class="button button-secondary photo-picker"><x-icon name="image" size="19"/> Choose photo
            <input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" aria-label="Choose a profile photo from your device" aria-describedby="photo-help" :disabled="busy || !online" wire:loading.attr="disabled" wire:target="savePhoto,removePhoto" x-on:change="choose($event, $wire)">
        </label>
    </div>
    <p class="small muted photo-help" id="photo-help">Up to 20 MB. Photos are resized and cropped to the center.</p>
    <p class="small muted" x-show="busy" x-cloak role="status" x-text="progress ? 'Uploading photo… ' + progress + '%' : 'Preparing photo…'"></p>
    <p class="error-notice" x-show="error" x-text="error" x-cloak role="alert"></p>
    @error('photo')<p class="error-notice" role="alert">{{ $message }}</p>@enderror
    <div class="button-row" x-show="ready" x-cloak>
        <button type="button" class="button button-primary" :disabled="busy || !online" wire:click="savePhoto" wire:loading.attr="disabled" wire:target="savePhoto,removePhoto"><span wire:loading.remove wire:target="savePhoto">Save photo</span><span wire:loading wire:target="savePhoto">Saving…</span></button>
        <button type="button" class="button button-secondary" :disabled="busy || !online" wire:loading.attr="disabled" wire:target="savePhoto,removePhoto" x-on:click="cancel($wire)">Cancel</button>
    </div>
    @if(auth()->user()->profile_photo_path)
        <button type="button" class="text-button danger-text" wire:click="removePhoto" wire:loading.attr="disabled" wire:target="removePhoto" :disabled="busy || !online" x-show="!ready">Remove photo</button>
    @endif
    <p class="small muted" x-show="!online" x-cloak>Reconnect to change your photo.</p>
</section>

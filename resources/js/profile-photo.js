// Native file pickers work in browsers and installed PWAs. No camera permission
// is requested until the member chooses Take photo on their device.
export const profilePhotoPicker = () => ({
    busy: false,
    ready: false,
    online: navigator.onLine,
    progress: 0,
    error: '',
    previewUrl: null,

    clearPreview() {
        if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
        this.previewUrl = null;
        this.ready = false;
    },
    destroy() { this.clearPreview(); },

    async choose(event, wire) {
        const file = event.target.files?.[0];
        event.target.value = ''; // Choosing the same image again must work too.
        if (!file || this.busy) return;
        this.error = '';
        if (!navigator.onLine) { this.error = 'Reconnect before adding a photo.'; return; }
        if (file.size > 20 * 1024 * 1024) { this.error = 'Choose a photo smaller than 20 MB.'; return; }
        const type = file.type || (/\.hei[cf]$/i.test(file.name) ? 'image/heic' : '');
        if (!['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'].includes(type)) {
            this.error = 'Choose a JPG, PNG, or WebP photo. For HEIC photos, export or share a JPG copy first.';
            return;
        }

        this.busy = true;
        this.progress = 0;
        this.clearPreview();
        const sourceUrl = URL.createObjectURL(file);
        try {
            await wire.discardPhoto();
            const image = new Image();
            image.src = sourceUrl;
            // Browsers apply camera EXIF orientation when decoding an image.
            try { await image.decode(); }
            catch { throw new Error('This browser cannot open that photo. Choose a JPG, PNG, or WebP copy.'); }
            if (Math.min(image.naturalWidth, image.naturalHeight) < 32) throw new Error('Choose a larger photo (at least 32 × 32 pixels).');
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = 512;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#f8f7f4';
            ctx.fillRect(0, 0, 512, 512);
            const side = Math.min(image.naturalWidth, image.naturalHeight);
            ctx.drawImage(image, (image.naturalWidth - side) / 2, (image.naturalHeight - side) / 2, side, side, 0, 0, 512, 512);
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', .88));
            if (!blob) throw new Error('This photo could not be prepared. Try another image.');
            this.previewUrl = URL.createObjectURL(blob);
            await new Promise((resolve, reject) => wire.upload('photo', new File([blob], 'profile.jpg', {type: 'image/jpeg'}), resolve,
                () => reject(new Error('Upload didn’t finish. Check your connection and choose the photo again.')),
                event => { this.progress = event.detail.progress; },
                () => reject(new Error('Upload cancelled. Choose a photo to try again.'))));
            this.ready = true;
        } catch (error) {
            this.clearPreview();
            this.error = error.message || 'Couldn’t upload this photo. Please try again.';
        } finally {
            URL.revokeObjectURL(sourceUrl);
            this.busy = false;
        }
    },
    async cancel(wire) {
        this.busy = true;
        this.error = '';
        try { await wire.discardPhoto(); this.clearPreview(); }
        catch { this.error = 'Reconnect and try again.'; }
        finally { this.busy = false; }
    },
});

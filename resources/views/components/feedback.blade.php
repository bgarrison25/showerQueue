@if($errors->any())<div class="error-notice" role="alert">{{ $errors->first() }}</div>@endif
@if($notice ?? '')<div class="notice" role="status"><x-icon name="check" size="19"/> {{ $notice }}</div>@endif
@if(session('success'))<div class="notice" role="status">{{ session('success') }}</div>@endif

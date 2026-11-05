<?php

return [
    'id' => env('GOOGLE_CALENDAR_ID', ''),
    // Base64 of the complete service-account JSON key; never commit that key.
    'credentials' => env('GOOGLE_CALENDAR_CREDENTIALS_BASE64', ''),
    'block_busy' => env('GOOGLE_CALENDAR_BLOCK_BUSY', true),
];

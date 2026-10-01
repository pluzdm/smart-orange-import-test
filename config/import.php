<?php

return [
    'batch_size' => env('IMPORT_BATCH_SIZE', 500),
    'max_upload_mib' => env('IMPORT_MAX_UPLOAD_MIB', 20),
];

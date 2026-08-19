<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Anchor disk
    |--------------------------------------------------------------------------
    |
    | Where the newest chain hash is written so it lives outside the database.
    | In production this should point at storage the app cannot rewrite freely
    | (an off-box disk, an object store with versioning, etc.).
    |
    */

    'anchor_disk' => env('VAULT_AUDIT_ANCHOR_DISK', 'local'),

];

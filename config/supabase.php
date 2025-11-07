<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Supabase URL
    |--------------------------------------------------------------------------
    |
    | The URL of your Supabase project.
    |
    */
    'url' => env('SUPABASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Supabase Anon Key
    |--------------------------------------------------------------------------
    |
    | The anonymous (public) key for your Supabase project.
    |
    */
    'key' => env('SUPABASE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Supabase Service Role Key
    |--------------------------------------------------------------------------
    |
    | The service role key for server-side operations with elevated privileges.
    |
    */
    'service_key' => env('SUPABASE_SERVICE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Storage Bucket Name
    |--------------------------------------------------------------------------
    |
    | The name of the Supabase storage bucket for church documents.
    |
    */
    'storage_bucket' => env('SUPABASE_STORAGE_BUCKET', 'church-documents'),
];

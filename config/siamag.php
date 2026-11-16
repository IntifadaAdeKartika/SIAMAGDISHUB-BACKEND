<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Koordinat Kantor Dishub Ponorogo & Geofencing
    |--------------------------------------------------------------------------
    |
    | Daftar lokasi kantor Dinas Perhubungan Ponorogo untuk validasi absensi:
    | 1. Kantor Sekretariat Dishub: -7.867828, 111.462034
    | 2. Kantor UPT PKB (KIR): -7.838971, 111.480587
    |
    | Radius toleransi maksimal: 70 meter.
    |
    */
    'max_distance_meters' => env('MAX_DISTANCE_METERS', 70),

    'offices' => [
        [
            'name' => 'Kantor Sekretariat Dishub',
            'latitude' => (float) env('DISHUB_SEKRETARIAT_LAT', -7.867828),
            'longitude' => (float) env('DISHUB_SEKRETARIAT_LON', 111.462034),
        ],
        [
            'name' => 'Kantor UPT PKB (KIR)',
            'latitude' => (float) env('DISHUB_UPT_PKB_LAT', -7.838971),
            'longitude' => (float) env('DISHUB_UPT_PKB_LON', 111.480587),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Disk
    |--------------------------------------------------------------------------
    |
    | Disk yang digunakan untuk menyimpan dokumen, foto selfie, paraf, dll.
    | Default: public (lokal: storage/app/public), dapat diswitch ke s3 / vercel.
    |
    */
    'storage_disk' => env('SIAMAG_STORAGE_DISK', 'public'),
];

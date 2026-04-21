<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Check-in Time Window
    |--------------------------------------------------------------------------
    |
    | Students can only check in within this time window (24h format).
    | Default: 08:00 - 17:00
    |
    */
    'check_in_window' => [
        'start' => '08:00',
        'end'   => '17:00',
    ],

    /*
    |--------------------------------------------------------------------------
    | Grace Period (Minutes)
    |--------------------------------------------------------------------------
    |
    | Allow backdating check-in within this many minutes.
    | Default: 15 minutes
    |
    */
    'grace_period_minutes' => env('ATTENDANCE_GRACE_PERIOD', 15),

    /*
    |--------------------------------------------------------------------------
    | Minimum Check-in Duration (Seconds)
    |--------------------------------------------------------------------------
    |
    | Minimum time between check-in and check-out to prevent fraud.
    | Default: 30 seconds
    |
    */
    'min_checkin_duration' => env('ATTENDANCE_MIN_DURATION', 30),

    /*
    |--------------------------------------------------------------------------
    | Rate Limit
    |--------------------------------------------------------------------------
    |
    | Max attendance attempts per minute per student.
    | Default: 5 attempts/minute
    |
    */
    'rate_limit' => [
        'max_attempts' => 5,
        'decay_minutes' => 1,
    ],
];

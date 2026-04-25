<?php

return [

    'check_in_window' => [
        'start' => '00:00',
        'end'   => '23:59',
    ],

    'grace_period_minutes' => env('ATTENDANCE_GRACE_PERIOD', 15),

    'min_checkin_duration' => env('ATTENDANCE_MIN_DURATION', 30),

    'rate_limit' => [
        'max_attempts' => 5,
        'decay_minutes' => 1,
    ],

    'face' => [
        'min_match_score'    => env('ATTENDANCE_FACE_MIN_MATCH', 60),
        'min_liveness_score' => env('ATTENDANCE_FACE_MIN_LIVENESS', 75),
    ],
];

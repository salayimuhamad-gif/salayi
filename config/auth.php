<?php

declare(strict_types=1);
use App\Modules\Identity\Models\User;

return [
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    // Spec 30.1 + 37.5: an administrator re-confirms before reaching any
    // sensitive surface. Three hours, not Laravel's default, because admin
    // sessions on shared devices are a realistic risk in an office setting.
    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];

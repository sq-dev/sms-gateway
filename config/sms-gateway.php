<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SMS Gateway configuration
|--------------------------------------------------------------------------
|
| This configuration file controls how the `sqdev/sms-gateway` package
| resolves SMS providers inside a Laravel application. The `default`
| connection is used whenever `SmsGateway::send(...)` is called without a
| connection name. Named connections can be targeted via
| `SmsGateway::provider('name')` (or the alias `SmsGateway::connection(...)`).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Default connection
    |--------------------------------------------------------------------------
    |
    | The name of the connection from the `connections` array that is used by
    | default. This is the connection that powers `SmsGateway::send(...)` and
    | constructor-injected `SmsGateway\Sender` instances.
    |
    */

    'default' => env('SMS_GATEWAY_CONNECTION', 'aliftech'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Each entry defines a named SMS connection. The `driver` key selects
    | which built-in provider adapter is used and the remaining keys map to
    | that adapter's constructor arguments. Add as many connections as you
    | need - you can freely mix multiple drivers or run the same driver with
    | different credentials.
    |
    | Supported drivers (built in):
    |   - "payom"   (SmsGateway\Providers\Payom\PayomSmsProvider)
    |   - "osonsms" (SmsGateway\Providers\OsonSms\OsonSmsProvider)
    |   - "aliftech" (SmsGateway\Providers\Aliftech\AliftechProvider)
    |
    | You can register custom drivers at runtime with
    | `SmsGateway::extend('my-driver', function ($app, $config, $name) { ... })`.
    |
    */

    'connections' => [

        'payom' => [
            'driver' => 'payom',
            'token' => env('PAYOM_JWT_TOKEN'),
            'default_sender_name' => env('PAYOM_DEFAULT_SENDER'),
            'base_uri' => env('PAYOM_BASE_URI'),
        ],

        'osonsms' => [
            'driver' => 'osonsms',
            'token' => env('OSONSMS_TOKEN'),
            'login' => env('OSONSMS_LOGIN'),
            'default_sender_name' => env('OSONSMS_DEFAULT_SENDER'),
            'base_uri' => env('OSONSMS_BASE_URI'),
        ],

        'aliftech' => [
            'driver' => 'aliftech',
            'api_key' => env('ALIFTECH_API_KEY'),
            'default_sender_name' => env('ALIFTECH_DEFAULT_SENDER'),
            // Accepts "common" (default), "otp", "batch" or the matching integer 1/2/3.
            'default_sms_type' => env('ALIFTECH_DEFAULT_SMS_TYPE'),
            'base_uri' => env('ALIFTECH_BASE_URI'),
        ],

    ],

];

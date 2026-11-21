<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Online Payment Gateway
    |--------------------------------------------------------------------------
    |
    | Empty: online payment is off and checkout works through WhatsApp only.
    | Otherwise the key of one of the gateways below. "fake" exists for local
    | testing and is refused in production. See docs/architecture/payments.md
    | for adding the real provider once its API details arrive.
    |
    */

    'default' => env('PAYMENT_GATEWAY', ''),

    /*
    |--------------------------------------------------------------------------
    | Unpaid Online Orders
    |--------------------------------------------------------------------------
    |
    | An order waiting for an online payment holds its stock. After this many
    | minutes without a confirmed payment, it is cancelled and the stock returned,
    | after asking the provider one last time.
    |
    */

    'pending_minutes' => (int) env('PAYMENT_PENDING_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Double Check Notifications
    |--------------------------------------------------------------------------
    |
    | A signed notification is already trusted, but when this is on the status
    | is read again from the provider's API before anything changes. Turn off
    | only for a provider without a status API.
    |
    */

    'verify_with_api' => (bool) env('PAYMENT_VERIFY_WITH_API', true),

    'gateways' => [

        // Local development and tests only: a page with Approve and Decline.
        'fake' => [
            'webhook_secret' => env('PAYMENT_FAKE_SECRET', 'local-fake-gateway-secret'),
        ],

        // The real provider goes here, for example:
        // 'grow' => [
        //     'api_url' => env('GROW_API_URL'),
        //     'api_key' => env('GROW_API_KEY'),
        //     'webhook_secret' => env('GROW_WEBHOOK_SECRET'),
        //     // Origins the customer's browser is sent to (Content Security Policy).
        //     'browser_origins' => ['https://secure.example-gateway.co.il'],
        //     // Addresses the provider sends notifications from, if it publishes them.
        //     'webhook_ips' => [],
        // ],

    ],

];

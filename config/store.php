<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Require Two-Factor Authentication For Staff
    |--------------------------------------------------------------------------
    |
    | When enabled, staff must set up two-factor authentication before they can
    | use the admin panel. It is on by default in production.
    |
    */

    'require_staff_two_factor' => (bool) env('STORE_REQUIRE_STAFF_2FA', env('APP_ENV') === 'production'),

    /*
    |--------------------------------------------------------------------------
    | Staff Idle Sign-Out
    |--------------------------------------------------------------------------
    |
    | Staff are signed out after this many minutes without any request. 0 keeps
    | them signed in for the normal session lifetime.
    |
    */

    'staff_idle_minutes' => (int) env('STORE_STAFF_IDLE_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Order WhatsApp Number
    |--------------------------------------------------------------------------
    |
    | Used only until a number is entered in the admin panel's store settings.
    |
    */

    'whatsapp' => env('STORE_WHATSAPP', ''),

    /*
    |--------------------------------------------------------------------------
    | Store Phone Number
    |--------------------------------------------------------------------------
    |
    | Shown in the footer as written here, until a number is entered in the
    | admin panel's store settings.
    |
    */

    'phone' => env('STORE_PHONE', ''),

    /*
    |--------------------------------------------------------------------------
    | Pending Order Expiry
    |--------------------------------------------------------------------------
    |
    | A WhatsApp order holds its stock until staff confirm it. An order still
    | pending and unpaid after this many hours is cancelled and its stock and
    | coupon use are returned. Moving it to "processing" in the admin panel
    | keeps it. Set to 0 to turn automatic expiry off.
    |
    */

    'pending_order_hours' => (int) env('STORE_PENDING_ORDER_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Checkout Abuse Limits
    |--------------------------------------------------------------------------
    |
    | Orders one phone number can place per day, and wrong coupon codes one
    | visitor can try per hour before coupon entry is locked for them.
    |
    */

    'orders_per_phone_per_day' => (int) env('STORE_ORDERS_PER_PHONE_PER_DAY', 3),

    'coupon_failures_per_hour' => (int) env('STORE_COUPON_FAILURES_PER_HOUR', 8),

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Sent on every HTML page. Report-only mode logs violations to /csp-report
    | without blocking anything, which is useful when adding a new third-party
    | script. See App\Http\Middleware\ContentSecurityPolicy.
    |
    */

    'csp' => [
        'enabled' => (bool) env('STORE_CSP_ENABLED', true),
        'report_only' => (bool) env('STORE_CSP_REPORT_ONLY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Empty when visitors reach the server directly. "cloudflare" when the site
    | is proxied by Cloudflare, so rate limits see the visitor's real address.
    | A comma separated list of addresses or ranges for any other proxy.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

];

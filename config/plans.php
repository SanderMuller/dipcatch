<?php declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Selling
    |--------------------------------------------------------------------------
    |
    | Null lets `App\Billing\BillingGate` decide from the Stripe settings
    | below: no keys, no shop. Set BILLING_ENABLED=false to keep the shop
    | shut on an environment that is otherwise fully configured — a staging
    | copy with live keys, for instance.
    |
    | The gate closes the shop only. Entitlements are untouched, so an
    | account granted Pro by hand keeps it, and anyone Stripe already bills
    | keeps reaching the billing portal.
    |
    | Closing the gate stops new checkouts. It cannot retract a Stripe
    | Checkout session that is already open — those live for hours and can
    | still be completed. Cancel them in Stripe if a closure must be
    | immediate.
    |
    */

    'enabled' => match (env('BILLING_ENABLED')) {
        null, '' => null,
        default => filter_var(env('BILLING_ENABLED'), FILTER_VALIDATE_BOOLEAN),
    },

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    |
    | Entitlements per plan. `App\Billing\Entitlements` is the only reader of
    | this section — never branch on the plan directly in a feature. The
    | `stripe` section below is pricing, read only by `App\Billing\ProPrice`.
    |
    | `max_products` and `max_shops_per_product` accept `null` for unlimited.
    |
    | `recheck_interval_hours` and `notifications_hourly_limit` fall back to
    | their `dipcatch` keys when unset, so the free plan keeps following the
    | app-wide cadence and cap. Set the PLAN_FREE_* variables only to move
    | the free plan away from those.
    |
    */

    'free' => [
        'max_products' => (int) env('PLAN_FREE_MAX_PRODUCTS', 20),
        'max_shops_per_product' => (int) env('PLAN_FREE_MAX_SHOPS_PER_PRODUCT', 4),
        'recheck_interval_hours' => env('PLAN_FREE_RECHECK_INTERVAL_HOURS'),
        'notifications_hourly_limit' => env('PLAN_FREE_NOTIFICATIONS_HOURLY_LIMIT'),
        'unit_price_alerts' => false,
        // Days of price history the chart will plot. Null is unlimited.
        // Data older than this is still stored — see the retention rule in
        // PruneOldChecksCommand — it simply cannot be read on this plan.
        'history_days' => (int) env('PLAN_FREE_HISTORY_DAYS', 90),
    ],

    'pro' => [
        'max_products' => null,
        'max_shops_per_product' => null,
        'recheck_interval_hours' => (int) env('PLAN_PRO_RECHECK_INTERVAL_HOURS', 2),
        'notifications_hourly_limit' => (int) env('PLAN_PRO_NOTIFICATIONS_HOURLY_LIMIT', 200),
        'unit_price_alerts' => true,
        'history_days' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    |
    | `price_id` is the Stripe Price the Pro checkout subscribes to. The
    | amount and currency below are for display only — Stripe remains the
    | authority on what a customer is actually charged.
    |
    */

    'stripe' => [
        'pro_price_id' => env('STRIPE_PRICE_PRO_MONTHLY'),
        'pro_amount' => env('PLAN_PRO_DISPLAY_AMOUNT', '2.99'),
        'pro_currency' => env('PLAN_PRO_DISPLAY_CURRENCY', 'EUR'),
        'trial_days' => (int) env('PLAN_PRO_TRIAL_DAYS', 14),
    ],

];

<?php declare(strict_types=1);

namespace App\Billing;

/** How often Pro is paid for. Monthly is always on sale; yearly only once its Stripe Price exists. */
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
}

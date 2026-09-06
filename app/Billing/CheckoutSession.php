<?php declare(strict_types=1);

namespace App\Billing;

/**
 * What Stripe says about a Checkout session this app started.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $status,
        public ?string $url,
    ) {}

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isPaid(): bool
    {
        return $this->status === 'complete';
    }
}

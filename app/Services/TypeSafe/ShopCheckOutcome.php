<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

/**
 * Same-product answers, or why there are none. A spent budget waits for the
 * next day; a failed request counts against a Klarna step's attempts.
 */
final readonly class ShopCheckOutcome
{
    public const string ANSWERED = 'answered';

    public const string BUDGET_SPENT = 'budget_spent';

    public const string FAILED = 'failed';

    /**
     * @param  array<string, float>  $answers  Keyed as the candidates were.
     */
    public function __construct(public string $reason, public array $answers = []) {}

    public function isDeferred(): bool
    {
        return $this->reason === self::BUDGET_SPENT;
    }
}

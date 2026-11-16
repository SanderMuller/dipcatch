<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Enums\ProbeFailure;
use App\Models\User;
use App\PriceAdapters\ShopSnapshot;
use App\Services\Checkjebon\CheckjebonSource;
use App\Services\PriceSources\ApiPriceSources;

/**
 * A probe's live read of a price API, paid from the account's probe budget.
 *
 * The API is read with the operator's credential and its rate limit, so it
 * spends from the budget as a page fetch does. Only the dataset read is
 * free: an account over the budget on a host the dataset also serves skips
 * the API and reads the dataset, so bulk adding still works.
 */
final readonly class BudgetedApiRead
{
    public function __construct(
        private ApiPriceSources $apiSources,
        private CheckjebonSource $checkjebon,
        private ProbeBudget $budget,
    ) {}

    /**
     * The reading, the refusal when the account is over its budget and no
     * dataset serves the host, or null to carry on without the API.
     *
     * @return array{0: ShopSnapshot, 1: string}|ProbeOutcome|null
     */
    public function __invoke(string $host, string $url, User $actor, bool $spendBudget): array|ProbeOutcome|null
    {
        $retryAfter = $spendBudget ? $this->budget->spend($actor) : null;

        if ($retryAfter === null) {
            return $this->apiSources->read($host, $url);
        }

        return $this->checkjebon->supports($host)
            ? null
            : ProbeOutcome::failed(ProbeFailure::ProbeRateLimited, ['retry_after_seconds' => $retryAfter]);
    }
}

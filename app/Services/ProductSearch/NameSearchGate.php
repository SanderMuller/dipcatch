<?php declare(strict_types=1);

namespace App\Services\ProductSearch;

use App\Models\User;
use App\Services\TypeSafe\CategorisationBudget;
use App\Services\TypeSafe\NameSearchQuestion;
use App\Services\TypeSafe\ShopCheckPurpose;
use App\Services\TypeSafe\TypeSafeClient;
use App\Services\TypeSafe\TypeSafeRequestFailed;

/**
 * Decides whether a typed text names one product, before a new paid web
 * search. Jev judges it for an account with the AI shop check on; everyone
 * else, and every Jev failure, gets the plain rule. It reads the local
 * results the wizard already holds and never searches again.
 */
final readonly class NameSearchGate
{
    /** Jev's too-vague chance from which the gate blocks. */
    private const float TOO_VAGUE_FROM = 0.5;

    /** The plain rule wants one word of at least this many letters. */
    private const int LONG_WORD_LETTERS = 4;

    public function __construct(
        private TypeSafeClient $client,
        private CategorisationBudget $budget,
    ) {}

    public function verdict(User $user, string $searchText, NameSearchResults $local): NameSearchVerdict
    {
        return $this->jevVerdict($user, $searchText, $local) ?? self::plainRule($searchText, $local);
    }

    /** Null when Jev is not asked or gives no answer. */
    private function jevVerdict(User $user, string $searchText, NameSearchResults $local): ?NameSearchVerdict
    {
        // The budget last: asking it spends a check.
        if (! $user->wantsShopChecks() || ! TypeSafeClient::configured() || ! $this->budget->allowsShopCheck($user, ShopCheckPurpose::NameSearch)) {
            return null;
        }

        try {
            $chances = $this->client->nameSearch($searchText, array_map(static fn (NameSearchResult $result): string => $result->title, $local->items));
        } catch (TypeSafeRequestFailed) {
            return null;
        }

        if (! isset($chances[NameSearchQuestion::TOO_VAGUE]) && ! isset($chances[NameSearchQuestion::SPECIFIC_PRODUCT])) {
            return null;
        }

        return ($chances[NameSearchQuestion::TOO_VAGUE] ?? 0.0) >= self::TOO_VAGUE_FROM
            ? NameSearchVerdict::TooVague
            : NameSearchVerdict::Specific;
    }

    /**
     * At least two words, one of them four letters or more, and no
     * catalogue result that holds every word.
     */
    private static function plainRule(string $searchText, NameSearchResults $local): NameSearchVerdict
    {
        $words = preg_split('/[^\p{L}\p{N}+]+/u', $searchText, flags: PREG_SPLIT_NO_EMPTY) ?: [];
        $hasLongWord = array_any($words, static fn (string $word): bool => preg_match_all('/\p{L}/u', $word) >= self::LONG_WORD_LETTERS);

        if (count($words) < 2 || ! $hasLongWord) {
            return NameSearchVerdict::TooVague;
        }

        return $local->fullMatch ? NameSearchVerdict::MatchesEveryWord : NameSearchVerdict::Specific;
    }
}

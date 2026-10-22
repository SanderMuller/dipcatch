<?php declare(strict_types=1);

namespace App\Services\ProductSearch;

/** The gate's answer on whether a typed text earns a paid web search. Kept in the wizard's locked state. */
enum NameSearchVerdict: string
{
    case Specific = 'specific';

    /** Add the brand or the size. */
    case TooVague = 'too_vague';

    /** A catalogue result holds every word: pick one, or change the text. */
    case MatchesEveryWord = 'matches_every_word';

    public function allowsSearch(): bool
    {
        return $this === self::Specific;
    }
}

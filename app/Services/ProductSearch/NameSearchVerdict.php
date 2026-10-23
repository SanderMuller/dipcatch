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

    /** Why the web search stays off for this text, or null when it may run. */
    public function hint(): ?string
    {
        if ($this === self::Specific) {
            return null;
        }

        return $this === self::TooVague
            ? __('Add the brand or the size, and search again.')
            : __('These results match every word. Pick one, or change the text to search the web.');
    }
}

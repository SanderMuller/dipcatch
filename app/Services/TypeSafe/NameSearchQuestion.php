<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

/** The `name_search` Choice's own parts: its choices, and reading its answer. */
final class NameSearchQuestion
{
    public const string SPECIFIC_PRODUCT = 'specific_product';

    public const string TOO_VAGUE = 'too_vague';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, float>  Keyed by choice; a choice the answer leaves out is left out.
     *
     * @throws TypeSafeRequestFailed
     */
    public static function chances(array $payload): array
    {
        $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
        $answer = $answers[TypeSafeClient::NAME_SEARCH_QUESTION] ?? null;
        $probabilities = is_array($answer) ? ($answer['probabilities'] ?? null) : null;

        if (! is_array($probabilities)) {
            throw new TypeSafeRequestFailed('TypeSafe answered without the name search answer.');
        }

        $chances = [];

        foreach ([self::SPECIFIC_PRODUCT, self::TOO_VAGUE] as $choice) {
            if (is_numeric($probabilities[$choice] ?? null)) {
                $chances[$choice] = max(0.0, min(1.0, (float) $probabilities[$choice]));
            }
        }

        return $chances;
    }
}

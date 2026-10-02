<?php declare(strict_types=1);

namespace App\Services\ListImport;

use App\Services\TypeSafe\TypeSafeClient;
use App\Services\TypeSafe\TypeSafeRequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Asks Jev which candidate each line of a pasted list means: one Choice
 * question per line, all in one request. The whole list is the state, so the
 * lines around one can settle what an abbreviation stands for.
 */
final readonly class ListMatchChooser
{
    public const string NONE = 'none';

    /**
     * @param  list<string>  $list  Every line, as pasted.
     * @param  array<string, array{line: string, options: array<string, string>}>  $lines  Keyed by a caller-chosen id; options keyed by a caller-chosen option id.
     * @return array<string, array{choice: ?string, probabilities: array<string, float>}>
     *
     * @throws TypeSafeRequestFailed
     */
    public function choose(array $list, ?string $shop, array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $state = $shop === null ? ['list' => $list] : ['list' => $list, 'shop' => $shop];
        $answers = $this->send(['model' => 'jev-latest', 'state' => $state, 'questions' => array_map(self::question(...), $lines)]);
        $picks = [];

        foreach (array_keys($lines) as $key) {
            $answer = is_array($answers[$key] ?? null) ? $answers[$key] : [];
            $picks[$key] = [
                'choice' => is_string($answer['choice'] ?? null) ? $answer['choice'] : null,
                'probabilities' => self::probabilities($answer['probabilities'] ?? null),
            ];
        }

        return $picks;
    }

    /**
     * @param  array{line: string, options: array<string, string>}  $line
     * @return array{type: string, instructions: string, criteria: array<string, string>}
     */
    private static function question(array $line): array
    {
        return [
            'type' => 'choice',
            'instructions' => "Which product does the line `{$line['line']}` of this shopping list or receipt mean? Receipts abbreviate words, cut names short and leave out the brand of the shop's own products. "
                . "A price on the line may include a discount or a count of items, and an option's price may be days old, so a different price does not rule an option out; use the price only to choose between pack sizes of the same product.",
            'criteria' => [...$line['options'], self::NONE => 'None of these: every option is a different product, variant or flavour than the line names'],
        ];
    }

    /**
     * @return array<string, float> Highest first.
     */
    private static function probabilities(mixed $raw): array
    {
        $probabilities = [];

        foreach (is_array($raw) ? $raw : [] as $option => $probability) {
            if (is_numeric($probability)) {
                $probabilities[(string) $option] = (float) $probability;
            }
        }

        arsort($probabilities);

        return $probabilities;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<array-key, mixed> The answers, keyed by question.
     *
     * @throws TypeSafeRequestFailed
     */
    private function send(array $body): array
    {
        if (! TypeSafeClient::configured()) {
            throw new TypeSafeRequestFailed('TypeSafe is not configured.');
        }

        try {
            $response = Http::withToken(trim(Config::string('services.typesafe.key')))
                ->acceptJson()
                ->timeout(Config::integer('dipcatch.categories.timeout_seconds'))
                ->post(TypeSafeClient::ENDPOINT, $body);
        } catch (ConnectionException $e) {
            throw new TypeSafeRequestFailed('TypeSafe unreachable: ' . $e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new TypeSafeRequestFailed("TypeSafe answered {$response->status()}.", status: $response->status());
        }

        $answers = $response->json('answers');

        return is_array($answers) ? $answers : [];
    }
}

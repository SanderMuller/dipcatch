<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

final readonly class SerperProvider implements WebSearchProvider
{
    public const string ENDPOINT = 'https://google.serper.dev/search';

    private const int TIMEOUT_SECONDS = 20;

    public function configured(): bool
    {
        return self::key() !== '';
    }

    public function search(string $query): array
    {
        try {
            $response = Http::withHeaders(['X-API-KEY' => self::key()])
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::ENDPOINT, [
                    'q' => $query,
                    'gl' => Config::string('dipcatch.web_discovery.country'),
                    'hl' => Config::string('dipcatch.web_discovery.language'),
                    'num' => Config::integer('dipcatch.web_discovery.results_per_search'),
                ]);
        } catch (ConnectionException $e) {
            throw new WebSearchFailed('Serper unreachable: ' . $e->getMessage(), $e->getCode(), previous: $e);
        }

        if (! $response->successful()) {
            throw new WebSearchFailed("Serper answered {$response->status()}.", $response->status());
        }

        // A body without the result list, or with rows none of which reads,
        // is not "nothing found": stored, it would stand for 90 days and
        // start every product with this title over with no findings.
        $organic = $response->json('organic');

        if (! is_array($organic)) {
            throw new WebSearchFailed('Serper answered without an organic result list.');
        }

        $results = [];

        foreach ($organic as $index => $row) {
            if (! is_array($row) || ! is_string($row['link'] ?? null)) {
                continue;
            }

            $results[] = [
                'title' => is_string($row['title'] ?? null) ? $row['title'] : '',
                'link' => $row['link'],
                'snippet' => is_string($row['snippet'] ?? null) ? $row['snippet'] : '',
                'position' => is_int($row['position'] ?? null) ? $row['position'] : (int) $index + 1,
            ];
        }

        if ($organic !== [] && $results === []) {
            throw new WebSearchFailed('Serper answered with results none of which carries a link.');
        }

        return $results;
    }

    private static function key(): string
    {
        return trim(Config::string('services.serper.key'));
    }
}

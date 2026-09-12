<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\MarketingPages;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Checks the Markdown twin Cloudflare serves to assistants.
 *
 * Cloudflare converts the HTML at the edge, so no application code produces
 * the Markdown and no test can cover it: this reads the live site. Kept out of
 * CI and off the scheduler on purpose — a build should not fail on a network
 * blip or on a toggle in someone else's dashboard.
 */
#[Description('Check that the Markdown twin of every marketing page is clean')]
#[Signature('seo:check-markdown {--url= : Origin to check, defaults to site.production_url}')]
final class CheckMarkdownTwinCommand extends Command
{
    public function handle(): int
    {
        $origin = $this->targetOrigin();

        $this->components->info('Checking the Markdown twin at ' . $origin);

        $failures = 0;

        foreach ($this->paths() as $path) {
            $failures += $this->checkPage($origin . $path);
        }

        if ($failures > 0) {
            $this->newLine();
            $this->components->error($failures . ' check(s) failed. The Markdown twin is not clean yet.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Every page converts cleanly.');

        return self::SUCCESS;
    }

    /**
     * @return int the number of failed checks for this page
     */
    private function checkPage(string $url): int
    {
        $response = Http::withHeaders(['Accept' => 'text/markdown'])->timeout(20)->get($url);

        if ($response->failed()) {
            $this->components->twoColumnDetail($url, '<fg=red>HTTP ' . $response->status() . '</>');

            return 1;
        }

        $contentType = $response->header('Content-Type');

        if (! str_contains($contentType, 'text/markdown')) {
            // Not a dirty twin: the edge conversion is off, or the plan changed.
            $this->components->twoColumnDetail($url, '<fg=red>not converted (' . $contentType . ')</>');

            return 1;
        }

        $body = $response->body();
        $problems = [];

        if (str_contains($body, '![](')) {
            $problems[] = 'decorative image reference';
        }

        if (str_contains($body, '9:41')) {
            $problems[] = 'phone mock chrome';
        }

        if (preg_match('/\\\\?\\+\\d+ more/', $body) === 1) {
            $problems[] = 'contradictory overflow count';
        }

        if (preg_match('/^title: (.*)$/m', $body, $match) === 1 && str_starts_with($match[1], 'dipcatch ')) {
            $problems[] = 'lowercase app name in frontmatter';
        }

        if (preg_match('/^image: (.*)$/m', $body, $match) === 1 && str_contains($match[1], 'apple-touch-icon')) {
            $problems[] = 'apple-touch-icon as the social image';
        }

        $tokens = $response->header('x-markdown-tokens');

        $this->components->twoColumnDetail(
            $url . ($tokens === '' ? '' : ' <fg=gray>(' . $tokens . ' tokens)</>'),
            $problems === [] ? '<fg=green>clean</>' : '<fg=red>' . implode(', ', $problems) . '</>',
        );

        return count($problems);
    }

    /**
     * The path of every marketing page, in both locales, taken from the same
     * list the sitemap uses so a new page is checked without being added here.
     *
     * @return list<string>
     */
    private function paths(): array
    {
        $paths = [];

        foreach (MarketingPages::all() as $entry) {
            $path = parse_url($entry['loc'], PHP_URL_PATH);
            $query = parse_url($entry['loc'], PHP_URL_QUERY);

            $paths[] = (is_string($path) ? $path : '/') . (is_string($query) ? '?' . $query : '');
        }

        return $paths;
    }

    private function targetOrigin(): string
    {
        $url = $this->option('url');

        if (is_string($url) && $url !== '') {
            return rtrim($url, '/');
        }

        return rtrim(Config::string('site.production_url'), '/');
    }
}

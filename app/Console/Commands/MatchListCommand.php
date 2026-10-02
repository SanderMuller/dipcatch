<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ListImport\Candidate;
use App\Services\ListImport\CatalogueCandidates;
use App\Services\ListImport\ListLine;
use App\Services\ListImport\ListMatchChooser;
use App\Services\TypeSafe\TypeSafeRequestFailed;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Prototype for importing a receipt or a typed list. A line may end in
 * `|| expected` to measure the match: every `&`-separated part must appear in
 * the candidate's name and size, and `-` means the catalogue lacks it.
 *
 * @phpstan-type Row array{line: ListLine, expected: ?string, candidates: list<Candidate>}
 * @phpstan-type Pick array{choice: ?string, probabilities: array<string, float>}
 */
#[Signature('dipcatch:match-list {file : A text file, one receipt or list line per line} {--chain= : The chain the receipt is from, as the catalogue names it (ah, jumbo, ...)} {--user= : Also match this user id\'s tracked products} {--jev : Ask Jev to choose among the candidates (one paid request)}')]
#[Description('Prototype: find catalogue candidates for each line of a receipt or list, and optionally let Jev choose. Reads only — writes nothing.')]
final class MatchListCommand extends Command
{
    private ?string $chain = null;

    public function handle(CatalogueCandidates $finder, ListMatchChooser $jev): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $this->chain = is_string($this->option('chain')) && $this->option('chain') !== '' ? $this->option('chain') : null;
        $user = is_numeric($this->option('user')) ? User::query()->find((int) $this->option('user')) : null;

        $started = microtime(true);
        $rows = $this->rows($path, $finder, $user);
        $searchMs = (int) round((microtime(true) - $started) * 1000);
        $picks = $this->option('jev') ? $this->picks($rows, $jev) : [];

        $tally = ['measured' => 0, 'inCatalogue' => 0, 'found' => 0, 'first' => 0, 'jev' => 0];

        foreach ($rows as $key => $row) {
            $this->report($row, $picks[$key] ?? null, $tally);
        }

        $this->summary(count($rows), $searchMs, $tally);

        return self::SUCCESS;
    }

    /**
     * @return array<string, Row>
     */
    private function rows(string $path, CatalogueCandidates $finder, ?User $user): array
    {
        $rows = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $index => $raw) {
            if (str_starts_with(trim($raw), '#')) {
                continue;
            }

            [$text, $expected] = array_pad(array_map(trim(...), explode('||', $raw, 2)), 2, null);
            $line = ListLine::of((string) $text);

            if ($line !== null) {
                $rows["line_{$index}"] = ['line' => $line, 'expected' => $expected === '' ? null : $expected, 'candidates' => $finder->for($line, $user, $this->chain)];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, Row>  $rows
     * @return array<string, Pick>
     */
    private function picks(array $rows, ListMatchChooser $jev): array
    {
        $questions = [];

        foreach ($rows as $key => $row) {
            if ($row['candidates'] !== []) {
                $options = [];

                foreach ($row['candidates'] as $i => $candidate) {
                    $options["c{$i}"] = $candidate->label();
                }

                $questions[$key] = ['line' => $row['line']->raw, 'options' => $options];
            }
        }

        $started = microtime(true);

        try {
            $picks = $jev->choose(array_values(array_map(static fn (array $row): string => $row['line']->raw, $rows)), $this->chain, $questions);
        } catch (TypeSafeRequestFailed $e) {
            $this->error('Jev failed: ' . $e->getMessage());

            return [];
        }

        $this->line(sprintf('Jev: %d questions in %d ms', count($questions), (int) round((microtime(true) - $started) * 1000)));

        return $picks;
    }

    /**
     * @param  Row  $row
     * @param  Pick|null  $pick
     * @param  array{measured: int, inCatalogue: int, found: int, first: int, jev: int}  $tally
     */
    private function report(array $row, ?array $pick, array &$tally): void
    {
        $this->newLine();
        $this->line("<options=bold>{$row['line']->text}</>" . ($row['expected'] !== null ? "  <fg=gray>expect: {$row['expected']}</>" : ''));

        $expected = $row['expected'];
        $goldIndex = $expected === null ? null : array_find_key($row['candidates'], fn (Candidate $candidate): bool => $candidate->meets($expected, $this->chain));
        $choice = $pick['choice'] ?? null;

        foreach (array_slice($row['candidates'], 0, 5) as $i => $candidate) {
            $marks = ($i === $goldIndex ? ' <fg=green>✓ expected</>' : '')
                . ($choice === "c{$i}" ? sprintf(' <fg=cyan>← Jev %.2f</>', $pick['probabilities']["c{$i}"] ?? 0) : '');
            $this->line(sprintf('  %d. %.2f  %s%s', $i + 1, $candidate->score, $candidate->label(), $marks));
        }

        if ($row['candidates'] === []) {
            $this->line('  <fg=yellow>no candidates</>');
        } elseif ($choice === ListMatchChooser::NONE) {
            $this->line(sprintf('  <fg=cyan>Jev: none of these (%.2f)</>', $pick['probabilities'][ListMatchChooser::NONE] ?? 0));
        }

        if ($expected === null) {
            return;
        }

        $tally['measured']++;
        $tally['inCatalogue'] += $expected === '-' ? 0 : 1;
        $tally['found'] += $goldIndex === null ? 0 : 1;
        $tally['first'] += $goldIndex === 0 ? 1 : 0;

        // Any option that meets the expectation counts: the receipt's price
        // may rightly pick another pack than search ranked first.
        $chosen = is_string($choice) && str_starts_with($choice, 'c') ? ($row['candidates'][(int) substr($choice, 1)] ?? null) : null;
        $tally['jev'] += match (true) {
            $goldIndex === null => $choice === ListMatchChooser::NONE ? 1 : 0,
            default => $chosen?->meets($expected, $this->chain) === true ? 1 : 0,
        };
    }

    /**
     * @param  array{measured: int, inCatalogue: int, found: int, first: int, jev: int}  $tally
     */
    private function summary(int $lines, int $searchMs, array $tally): void
    {
        $this->newLine();
        $this->line(sprintf('%d lines, candidate search %d ms', $lines, $searchMs));

        if ($tally['measured'] === 0) {
            return;
        }

        $this->line(sprintf('Lines whose product the catalogue holds: %d/%d', $tally['inCatalogue'], $tally['measured']));
        $this->line(sprintf('  of those, expected product among the candidates: %d/%d', $tally['found'], $tally['inCatalogue']));
        $this->line(sprintf('  of those, ranked first by search alone: %d/%d', $tally['first'], $tally['inCatalogue']));

        if ($this->option('jev')) {
            $this->line(sprintf('Jev chose the expected product, or none rightly: %d/%d', $tally['jev'], $tally['measured']));
        }
    }
}

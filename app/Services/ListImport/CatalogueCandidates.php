<?php declare(strict_types=1);

namespace App\Services\ListImport;

use App\Models\CheckjebonPrice;
use App\Models\Product;
use App\Models\User;
use App\Support\PackSize;
use Illuminate\Support\Str;

/**
 * The few products a list line most likely means, for Jev to choose from:
 * the account's own products first, then the checkjebon catalogue.
 *
 * Receipt words are mostly the start of a real word ("HALFV" for halfvolle),
 * so a row matches when it contains every word of the line, and a match at
 * the start of a word scores above one inside it. With no row holding every
 * word, one word may be missing.
 */
final class CatalogueCandidates
{
    private const int LIMIT = 8;

    /**
     * The whole catalogue in memory, read once per process. A prototype
     * shortcut: in the app this would be a stored, indexed search column.
     *
     * @var list<array{chain: string, id: string, name: string, size: ?string, price: string, search: string}>|null
     */
    private static ?array $index = null;

    /**
     * @return list<Candidate>
     */
    public function for(ListLine $line, ?User $user = null, ?string $chain = null): array
    {
        $tracked = $user instanceof User ? $this->tracked($line, $user) : [];
        $catalogue = $this->catalogue($line, $chain);

        $all = [...$tracked, ...$catalogue];
        usort($all, static fn (Candidate $a, Candidate $b): int => $b->score <=> $a->score);

        return array_slice($all, 0, self::LIMIT);
    }

    /**
     * @return list<Candidate>
     */
    private function tracked(ListLine $line, User $user): array
    {
        $candidates = [];

        foreach (Product::query()->where('user_id', $user->id)->get(['id', 'title']) as $product) {
            $score = self::score($line, $product->title, size: null);

            if ($score >= 0.5) {
                // A product the account already tracks is what a vague line
                // most likely means, so it outranks a catalogue row of the
                // same score.
                $candidates[] = new Candidate('tracked:' . $product->id, $product->title, size: null, chain: 'tracked', score: $score + 0.15, tracked: true);
            }
        }

        return $candidates;
    }

    /**
     * @return list<Candidate>
     */
    private function catalogue(ListLine $line, ?string $chain): array
    {
        // The first four letters only: Dutch changes a word's ending
        // ("jonge", "jong") and receipts cut it off, and the score below
        // still ranks a whole-word match first.
        $needles = array_values(array_unique(array_map(
            static fn (string $word): string => mb_substr($word, 0, 4),
            array_filter($line->words, static fn (string $word): bool => mb_strlen($word) >= 3),
        )));

        if ($needles === []) {
            $needles = $line->words;
        }

        $rows = $this->rowsHolding($needles);

        // One word may be missing: a receipt abbreviation that is not the
        // start of any word, or a word the catalogue leaves out ("BASIC").
        $chainHasRows = $chain === null || array_any($rows, static fn (array $row): bool => $row['chain'] === $chain);

        if ((! $chainHasRows || $rows === []) && count($needles) > 1) {
            foreach (array_keys($needles) as $skip) {
                $rows = [...$rows, ...$this->rowsHolding(array_values(array_diff_key($needles, [$skip => true])))];
            }
        }

        $candidates = [];

        foreach ($rows as $row) {
            // The catalogue lists some products twice under two ids.
            $key = "{$row['chain']}:" . mb_strtolower("{$row['name']}|{$row['size']}");
            $score = self::score($line, $row['name'], $row['size']) + ($chain !== null && $row['chain'] === $chain ? 0.1 : 0.0);
            $candidates[$key] = new Candidate("{$row['chain']}:{$row['id']}", $row['name'], $row['size'], $row['chain'], $score, price: $row['price']);
        }

        return array_values($candidates);
    }

    /**
     * Catalogue rows whose name holds every needle, matched on the same
     * ASCII lowercase form as the line, so "calve" finds "Calvé" and "lays"
     * finds "Lay's". In production this would be a stored, indexed column.
     *
     * @param  list<string>  $needles
     * @return list<array{chain: string, id: string, name: string, size: ?string, price: string, search: string}>
     */
    private function rowsHolding(array $needles): array
    {
        $rows = [];

        foreach (self::catalogueIndex() as $row) {
            foreach ($needles as $needle) {
                if (! str_contains($row['search'], $needle)) {
                    continue 2;
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array{chain: string, id: string, name: string, size: ?string, price: string, search: string}>
     */
    private static function catalogueIndex(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];

        foreach (CheckjebonPrice::query()->select(['supermarket', 'external_id', 'name', 'size', 'price'])->toBase()->cursor() as $row) {
            $index[] = [
                'chain' => self::text($row->supermarket ?? null),
                'id' => self::text($row->external_id ?? null),
                'name' => self::text($row->name ?? null),
                'size' => is_string($row->size ?? null) ? $row->size : null,
                'price' => self::text($row->price ?? null),
                'search' => self::normalise(self::text($row->name ?? null)),
            ];
        }

        return self::$index = $index;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** ASCII lowercase with every run of other characters as one space, apostrophes dropped. */
    public static function normalise(string $text): string
    {
        $ascii = str_replace("'", '', mb_strtolower(Str::ascii($text)));

        return trim(preg_replace('/[^a-z0-9+]+/', ' ', $ascii) ?? '');
    }

    /**
     * How well a product name answers the line: each line word scores 1 for
     * a whole word, 0.8 for the start of a word, 0.4 inside one, averaged;
     * words the name adds cost a little, and a pack size that differs costs
     * more than one that matches earns.
     */
    public static function score(ListLine $line, string $name, ?string $size): float
    {
        $words = array_values(array_filter(explode(' ', self::normalise($name))));

        if ($words === []) {
            return 0.0;
        }

        $total = 0.0;
        $used = [];

        foreach ($line->words as $lineWord) {
            $best = 0.0;
            $bestIndex = null;

            foreach ($words as $index => $word) {
                $match = match (true) {
                    $word === $lineWord => 1.0,
                    str_starts_with($word, $lineWord) => 0.8,
                    mb_strlen($word) >= 4 && str_starts_with($lineWord, $word) => 0.7,
                    str_contains($word, $lineWord) => 0.4,
                    default => 0.0,
                };

                if ($match > $best) {
                    $best = $match;
                    $bestIndex = $index;
                }
            }

            $total += $best;

            if ($bestIndex !== null) {
                $used[$bestIndex] = true;
            }
        }

        $score = $total / count($line->words);
        $extra = count(array_filter($words, static fn (string $word): bool => ! ctype_digit($word))) - count($used);
        $score -= 0.03 * max(0, $extra);

        $rowSize = PackSize::resolve($size, authoritative: false, title: $name);

        if ($line->size instanceof PackSize && $rowSize instanceof PackSize && $line->size->unit === $rowSize->unit) {
            $score += $line->size->isSameSizeAs($rowSize) ? 0.15 : -0.2;
        }

        return round($score, 3);
    }
}

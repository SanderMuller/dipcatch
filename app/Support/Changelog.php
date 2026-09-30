<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\ChangelogCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

/**
 * The entries of the in-app "What's new" page, from `changelog.entries`.
 *
 * A malformed entry throws rather than being skipped, so a bad date or an
 * unknown category fails the test suite instead of vanishing from the page.
 */
final class Changelog
{
    /**
     * Newest first. Entries that share a date keep their order in the config.
     *
     * @return list<ChangelogEntry>
     */
    public static function entries(): array
    {
        $entries = [];

        foreach (Config::array('changelog.entries', []) as $index => $row) {
            $entries[] = self::entry($index, $row);
        }

        usort($entries, static fn (ChangelogEntry $a, ChangelogEntry $b): int => $b->date->getTimestamp() <=> $a->date->getTimestamp());

        return $entries;
    }

    /**
     * The entries grouped by the day they shipped, newest day first.
     *
     * @return list<array{date: CarbonImmutable, entries: non-empty-list<ChangelogEntry>}>
     */
    public static function byDate(): array
    {
        $days = [];

        foreach (self::entries() as $entry) {
            $key = $entry->date->toDateString();
            $days[$key] ??= ['date' => $entry->date, 'entries' => []];
            $days[$key]['entries'][] = $entry;
        }

        return array_values($days);
    }

    private static function entry(int|string $index, mixed $row): ChangelogEntry
    {
        if (! is_array($row)
            || ! is_string($row['date'] ?? null)
            || ! is_string($row['category'] ?? null)
            || ! is_string($row['title'] ?? null) || trim($row['title']) === ''
            || ! is_string($row['body'] ?? null) || trim($row['body']) === '') {
            throw new InvalidArgumentException("changelog.entries.{$index} needs a date, category, title and body.");
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $row['date']);

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $row['date']) {
            throw new InvalidArgumentException("changelog.entries.{$index} has date \"{$row['date']}\"; use YYYY-MM-DD.");
        }

        $category = ChangelogCategory::tryFrom($row['category'])
            ?? throw new InvalidArgumentException("changelog.entries.{$index} has unknown category \"{$row['category']}\".");

        if (isset($row['video'], $row['image'])) {
            throw new InvalidArgumentException("changelog.entries.{$index} has both a video and an image; pick one.");
        }

        return new ChangelogEntry(
            $date,
            $category,
            trim($row['title']),
            $row['body'],
            link: self::link($index, $row),
            video: self::video($index, $row),
            image: self::image($index, $row),
        );
    }

    /**
     * @param  array<mixed>  $row
     */
    private static function video(int|string $index, array $row): ?string
    {
        if (! array_key_exists('video', $row)) {
            return null;
        }

        if (! is_string($row['video']) || preg_match('/^[a-z0-9-]+$/', $row['video']) !== 1) {
            throw new InvalidArgumentException("changelog.entries.{$index}.video must be a slug such as \"best-buys-here\".");
        }

        return $row['video'];
    }

    /**
     * @param  array<mixed>  $row
     * @return array{route: string, label: string}|null
     */
    private static function link(int|string $index, array $row): ?array
    {
        if (! array_key_exists('link', $row)) {
            return null;
        }

        return [
            'route' => self::filled($index, $row['link'], 'link', 'route'),
            'label' => self::filled($index, $row['link'], 'link', 'label'),
        ];
    }

    /**
     * @param  array<mixed>  $row
     * @return array{src: string, alt: string}|null
     */
    private static function image(int|string $index, array $row): ?array
    {
        if (! array_key_exists('image', $row)) {
            return null;
        }

        return [
            'src' => self::filled($index, $row['image'], 'image', 'src'),
            'alt' => self::filled($index, $row['image'], 'image', 'alt'),
        ];
    }

    private static function filled(int|string $index, mixed $value, string $key, string $field): string
    {
        if (! is_array($value) || ! is_string($value[$field] ?? null) || trim($value[$field]) === '') {
            throw new InvalidArgumentException("changelog.entries.{$index}.{$key} needs a non-empty {$field}.");
        }

        return $value[$field];
    }
}

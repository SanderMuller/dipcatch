<?php declare(strict_types=1);

namespace App\Services\Scraper;

final class PriceParser
{
    /**
     * Normalize a raw price-bearing string to a decimal string.
     *
     * Returns null if no number can be extracted.
     */
    public function parse(string $raw): ?string
    {
        $matches = [];
        if (preg_match_all('/-?\d[\d.,\s]*\d|-?\d/', $raw, $matches) === false || $matches[0] === []) {
            return null;
        }

        foreach ($matches[0] as $candidate) {
            $normalized = $this->normalize($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalize(string $candidate): ?string
    {
        $candidate = preg_replace('/\s+/', '', $candidate) ?? '';
        if ($candidate === '' || $candidate === '-') {
            return null;
        }

        $negative = str_starts_with($candidate, '-');
        if ($negative) {
            $candidate = substr($candidate, 1);
        }

        $hasDot = str_contains($candidate, '.');
        $hasComma = str_contains($candidate, ',');

        if ($hasDot && $hasComma) {
            // Both separators present here (str_contains'd above), so strrpos is non-false.
            $lastDot = (int) strrpos($candidate, '.');
            $lastComma = (int) strrpos($candidate, ',');
            // Rightmost separator is the decimal mark; the other is thousands.
            if ($lastComma > $lastDot) {
                $integer = str_replace(['.', ','], ['', '.'], substr($candidate, 0, $lastComma));
                $fraction = substr($candidate, $lastComma + 1);
            } else {
                $integer = str_replace([',', '.'], '', substr($candidate, 0, $lastDot));
                $fraction = substr($candidate, $lastDot + 1);
            }
            $value = $integer . '.' . $fraction;
        } elseif ($hasDot || $hasComma) {
            $separator = $hasDot ? '.' : ',';
            $parts = explode($separator, $candidate);
            $tail = end($parts);
            // Two-digit tail with a single separator → decimal. Otherwise (e.g. "1.234"
            // or "12,345") treat the separator as thousands.
            if (count($parts) === 2 && strlen($tail) === 2) {
                $value = $parts[0] . '.' . $tail;
            } else {
                $value = implode('', $parts);
            }
        } else {
            $value = $candidate;
        }

        if (! preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }

        return ($negative ? '-' : '') . $value;
    }
}

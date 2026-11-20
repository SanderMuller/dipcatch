<?php declare(strict_types=1);

namespace App\Analytics\Countries;

use App\Analytics\Contracts\ResolvesCountry;
use Illuminate\Http\Request;

/**
 * The visitor's country from the `CF-IPCountry` header Cloudflare adds.
 * `XX` (unknown) and `T1` (Tor) count as unknown.
 */
final class CloudflareCountry implements ResolvesCountry
{
    public function country(Request $request): ?string
    {
        $header = $request->header('CF-IPCountry');

        if (! is_string($header)) {
            return null;
        }

        $country = strtoupper(trim($header));

        if (preg_match('/^[A-Z]{2}$/', $country) !== 1 || in_array($country, ['XX', 'T1'], true)) {
            return null;
        }

        return $country;
    }
}

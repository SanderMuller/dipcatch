<?php declare(strict_types=1);

namespace App\Analytics\Contracts;

use Illuminate\Http\Request;

interface ResolvesCountry
{
    /** ISO 3166-1 alpha-2, upper case, or null when unknown. */
    public function country(Request $request): ?string;
}

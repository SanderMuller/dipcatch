<?php declare(strict_types=1);

namespace App\Analytics\Contracts;

use App\Analytics\Enums\PageViewSection;
use Illuminate\Http\Request;

interface ResolvesSection
{
    public function section(Request $request): PageViewSection;
}

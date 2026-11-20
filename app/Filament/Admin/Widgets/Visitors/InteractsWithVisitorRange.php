<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Visitors;

use Filament\Widgets\Concerns\InteractsWithPageFilters;

trait InteractsWithVisitorRange
{
    use InteractsWithPageFilters;

    protected function visitorRange(): VisitorRange
    {
        return VisitorRange::fromFilters($this->pageFilters);
    }
}

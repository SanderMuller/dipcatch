<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Visitors;

use App\Analytics\Enums\StatDimension;

final class TopCountriesWidget extends TopListWidget
{
    protected function dimension(): StatDimension
    {
        return StatDimension::Country;
    }

    protected function listHeading(): string
    {
        return 'Countries';
    }

    protected function emptyValueLabel(): string
    {
        return 'Unknown';
    }
}

<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Visitors;

use App\Analytics\Enums\StatDimension;

final class TopUtmSourcesWidget extends TopListWidget
{
    protected function dimension(): StatDimension
    {
        return StatDimension::UtmSource;
    }

    protected function listHeading(): string
    {
        return 'UTM sources';
    }

    protected function emptyValueLabel(): string
    {
        return 'None';
    }
}

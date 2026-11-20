<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Visitors;

use App\Analytics\Enums\StatDimension;

final class TopDevicesWidget extends TopListWidget
{
    protected function dimension(): StatDimension
    {
        return StatDimension::Device;
    }

    protected function listHeading(): string
    {
        return 'Devices';
    }

    protected function emptyValueLabel(): string
    {
        return 'Unknown';
    }
}

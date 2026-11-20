<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Visitors;

use App\Analytics\Enums\StatDimension;

final class TopUtmCampaignsWidget extends TopListWidget
{
    protected function dimension(): StatDimension
    {
        return StatDimension::UtmCampaign;
    }

    protected function listHeading(): string
    {
        return 'UTM campaigns';
    }

    protected function emptyValueLabel(): string
    {
        return 'None';
    }
}

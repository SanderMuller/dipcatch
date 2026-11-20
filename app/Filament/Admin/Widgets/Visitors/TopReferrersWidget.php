<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Visitors;

use App\Analytics\Enums\StatDimension;

final class TopReferrersWidget extends TopListWidget
{
    protected function dimension(): StatDimension
    {
        return StatDimension::Referrer;
    }

    protected function listHeading(): string
    {
        return 'Referrers';
    }

    protected function emptyValueLabel(): string
    {
        return 'Direct';
    }
}

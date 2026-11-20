<?php declare(strict_types=1);

namespace App\Filament\Admin\Widgets\Visitors;

use App\Analytics\Enums\PageViewSection;
use App\Analytics\Enums\StatDimension;

final class TopPagesWidget extends TopListWidget
{
    protected function dimension(): StatDimension
    {
        return StatDimension::Path;
    }

    protected function listHeading(): string
    {
        return 'Marketing pages';
    }

    protected function emptyValueLabel(): string
    {
        return '(none)';
    }

    protected function section(): PageViewSection
    {
        return PageViewSection::Marketing;
    }
}

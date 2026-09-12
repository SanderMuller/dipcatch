<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscribers\Pages;

use App\Filament\Admin\Resources\Subscribers\SubscriberResource;
use App\Filament\Admin\Widgets\RevenueOverviewWidget;
use Filament\Resources\Pages\ListRecords;

class ListSubscribers extends ListRecords
{
    protected static string $resource = SubscriberResource::class;

    /**
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            RevenueOverviewWidget::class,
        ];
    }
}

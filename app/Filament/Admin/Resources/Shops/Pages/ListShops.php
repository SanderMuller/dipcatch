<?php declare(strict_types=1);

namespace App\Filament\Admin\Resources\Shops\Pages;

use App\Filament\Admin\Resources\Shops\ShopResource;
use App\Support\ShopDataExport;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ListShops extends ListRecords
{
    protected static string $resource = ShopResource::class;

    /**
     * CSV downloads for product analysis. They carry no owner data; see
     * {@see ShopDataExport}.
     *
     * @return array<int, ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('export_hosts')
                    ->label('Hosts (CSV)')
                    ->action(fn (): StreamedResponse => response()->streamDownload(
                        fn () => ShopDataExport::writeCsv(ShopDataExport::hosts()),
                        'dipcatch-hosts-' . now()->format('Y-m-d') . '.csv',
                        ['Content-Type' => 'text/csv'],
                    )),
                Action::make('export_urls')
                    ->label('Product URLs (CSV)')
                    ->action(fn (): StreamedResponse => response()->streamDownload(
                        fn () => ShopDataExport::writeCsv(ShopDataExport::urls()),
                        'dipcatch-product-urls-' . now()->format('Y-m-d') . '.csv',
                        ['Content-Type' => 'text/csv'],
                    )),
            ])
                ->label('Export for analysis')
                ->icon(Heroicon::ArrowDownTray)
                ->button(),
        ];
    }
}

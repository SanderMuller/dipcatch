<?php declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\WaitlistSignup;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

class WaitlistSignupExporter extends Exporter
{
    protected static ?string $model = WaitlistSignup::class;

    /**
     * @return array<int, ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('email'),
            ExportColumn::make('ip_address')->label('IP'),
            ExportColumn::make('user_agent')->label('User agent'),
            ExportColumn::make('created_at')->label('Joined at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your waitlist signup export has completed and ' . Number::format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . Number::format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }
}

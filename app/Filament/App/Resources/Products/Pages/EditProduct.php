<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Pages;

use App\Actions\Drops\DetectDrop;
use App\Actions\Scraper\RecordScrape;
use App\Enums\ScrapeStatus;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Product;
use App\Services\Scraper\Scraper;
use App\Services\Scraper\ScrapeRequest;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Components\ViewComponent;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /** Cooldown after a successful manual re-scrape, in seconds. */
    public const int RESCRAPE_COOLDOWN_SECONDS = 3600;

    /**
     * @return ViewComponent[]
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->rescrapeAction(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    private function rescrapeAction(): Action
    {
        return Action::make('rescrape')
            ->label('Re-scrape now')
            ->icon(Heroicon::ArrowPath)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription('Run a manual scrape outside the 24h cycle. Limited to once per hour after a successful run.')
            ->action(function (Product $record): void {
                $key = self::cooldownKey($record->id);

                if (RateLimiter::tooManyAttempts($key, maxAttempts: 1)) {
                    $secondsLeft = RateLimiter::availableIn($key);
                    Notification::make()
                        ->title('Re-scrape on cooldown')
                        ->body("Try again in {$secondsLeft}s.")
                        ->warning()
                        ->send();

                    return;
                }

                $result = app(Scraper::class)->scrape(ScrapeRequest::fromProduct($record));

                if ($result->status === ScrapeStatus::Throttled) {
                    Notification::make()
                        ->title('Throttled')
                        ->body('Host requested a slow-down. Try again shortly.')
                        ->warning()
                        ->send();

                    return;
                }

                (app(RecordScrape::class))($record, $result);

                if ($result->status === ScrapeStatus::Ok) {
                    RateLimiter::hit($key, decaySeconds: self::RESCRAPE_COOLDOWN_SECONDS);

                    $fresh = $record->fresh();
                    if ($fresh !== null) {
                        (app(DetectDrop::class))($fresh);
                    }

                    Notification::make()
                        ->title('Re-scrape ok')
                        ->body("New price: {$result->currency} {$result->price}")
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Re-scrape: ' . $result->status->value)
                        ->body($result->error ?? '')
                        ->danger()
                        ->send();
                }
            });
    }

    public static function cooldownKey(string $productId): string
    {
        return "rescrape:cooldown:{$productId}";
    }

    /**
     * Convert DB array (`['.foo','.bar']`) to Repeater rows
     * (`[['selector' => '.foo'], ...]`) on form load.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var list<string> $rawFallbacks */
        $rawFallbacks = is_array($data['fallback_selectors'] ?? null) ? $data['fallback_selectors'] : [];
        $data['fallback_selectors'] = array_map(
            fn (string $selector): array => ['selector' => $selector],
            array_values(array_filter($rawFallbacks, fn (string $s): bool => $s !== '')),
        );

        return $data;
    }

    /**
     * Convert Repeater rows back to a flat string array on save.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var array<int, array{selector?: string}> $rows */
        $rows = is_array($data['fallback_selectors'] ?? null) ? $data['fallback_selectors'] : [];
        $data['fallback_selectors'] = array_values(array_filter(
            array_map(fn (array $row): string => $row['selector'] ?? '', $rows),
            fn (string $s): bool => $s !== '',
        ));

        return $data;
    }
}

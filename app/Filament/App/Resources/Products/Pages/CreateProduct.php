<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Pages;

use App\Actions\Products\AutoDetect;
use App\Enums\ScrapeStatus;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\User;
use App\Rules\ValidCssSelector;
use App\Services\Drops\TierDefaults;
use App\Services\Scraper\Scraper;
use App\Services\Scraper\ScrapeRequest;
use App\Support\Iso4217;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateProduct extends CreateRecord
{
    use HasWizard;

    protected static string $resource = ProductResource::class;

    /**
     * Latest preview result, serialized to a plain array so Livewire can
     * round-trip it. Keys mirror `App\Services\Scraper\ScrapeResult`.
     *
     * @var array<string, mixed>|null
     */
    public ?array $previewData = null;

    public ?int $previewedAt = null;

    /**
     * Hash of the form fields that determined the preview's price. Compared on
     * save so a user can't edit URL/selectors/currency after a successful
     * preview and have us persist a baseline price from the previous fetch.
     */
    public ?string $previewFingerprint = null;

    /** Maximum age of a preview the form will accept on save. */
    public const int PREVIEW_TTL_SECONDS = 300;

    protected function getSteps(): array
    {
        return [
            Step::make('Find product')
                ->description('Paste the URL and optionally auto-detect.')
                ->schema([
                    TextInput::make('url')
                        ->label('Product URL')
                        ->url()
                        ->maxLength(2048)
                        ->required()
                        ->live(onBlur: true),

                    Action::make('detect')
                        ->label('Auto-detect')
                        ->icon(Heroicon::Sparkles)
                        ->color('gray')
                        ->action(fn (): null => $this->runDetect()),
                ]),

            Step::make('Selectors & preview')
                ->description('Confirm the selector by previewing the price.')
                ->schema([
                    TextInput::make('title')
                        ->label('Title')
                        ->maxLength(255)
                        ->required(),

                    TextInput::make('image_url')
                        ->label('Image URL')
                        ->url()
                        ->maxLength(2048),

                    TextInput::make('price_selector')
                        ->label('CSS selector for price')
                        ->maxLength(500)
                        ->rules([new ValidCssSelector()])
                        ->required(),

                    Repeater::make('fallback_selectors')
                        ->label('Fallback selectors (optional)')
                        ->schema([
                            TextInput::make('selector')
                                ->maxLength(500)
                                ->rules([new ValidCssSelector()])
                                ->required(),
                        ])
                        ->defaultItems(0)
                        ->reorderable()
                        ->addActionLabel('Add fallback selector'),

                    TextInput::make('image_selector')
                        ->label('Image selector (optional override)')
                        ->maxLength(500)
                        ->rules([new ValidCssSelector()]),
                    TextInput::make('title_selector')
                        ->label('Title selector (optional override)')
                        ->maxLength(500)
                        ->rules([new ValidCssSelector()]),

                    Select::make('currency')
                        ->options(Iso4217::options())
                        ->default(function (): string {
                            /** @var User|null $user */
                            $user = auth()->user();

                            return is_string($user?->default_currency) ? $user->default_currency : 'EUR';
                        })
                        ->required(),

                    TextInput::make('drop_threshold_pct')
                        ->label('Drop threshold (%)')
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(99.99)
                        ->step(0.01)
                        ->required(),

                    TextInput::make('drop_threshold_abs')
                        ->label('Drop threshold (absolute)')
                        ->numeric()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->required(),

                    Action::make('preview')
                        ->label('Preview scrape')
                        ->icon(Heroicon::ArrowPath)
                        ->color('primary')
                        ->action(fn (): null => $this->runPreview()),

                    Section::make('Preview result')
                        ->collapsible()
                        ->visible(fn (): bool => $this->previewData !== null)
                        ->schema([]),
                ]),
        ];
    }

    public function runDetect(): null
    {
        $url = $this->dataString('url');
        if ($url === '') {
            Notification::make()->title('Enter a URL first.')->warning()->send();

            return null;
        }

        $result = (app(AutoDetect::class))($url);

        if ($result->error !== null) {
            Notification::make()
                ->title('Auto-detect failed')
                ->body($result->error)
                ->danger()
                ->send();

            return null;
        }

        if ($result->selectors !== []) {
            $this->data['price_selector'] = $result->selectors[0];
        }
        if ($result->title !== null) {
            $this->data['title'] = $result->title;
        }
        if ($result->imageUrl !== null) {
            $this->data['image_url'] = $result->imageUrl;
        }

        Notification::make()
            ->title($result->selectors === []
                ? 'No DOM selector candidates found — enter one manually.'
                : 'Suggested selector: ' . $result->selectors[0])
            ->success()
            ->send();

        return null;
    }

    public function runPreview(): null
    {
        $url = $this->dataString('url');
        $priceSelector = $this->dataString('price_selector');

        if ($url === '' || $priceSelector === '') {
            Notification::make()
                ->title('URL and price selector are required to preview.')
                ->warning()
                ->send();

            return null;
        }

        $request = new ScrapeRequest(
            url: $url,
            priceSelector: $priceSelector,
            fallbackSelectors: $this->collectFallbackSelectors(),
            imageSelector: $this->dataString('image_selector') !== '' ? $this->dataString('image_selector') : null,
            titleSelector: $this->dataString('title_selector') !== '' ? $this->dataString('title_selector') : null,
            preferredCurrency: $this->dataString('currency') !== '' ? $this->dataString('currency') : null,
        );

        $result = app(Scraper::class)->scrape($request);

        /** @var array<string, mixed> $payload */
        $payload = $result->toArray();
        $this->previewData = $payload;
        $this->previewedAt = Carbon::now()->getTimestamp();
        $this->previewFingerprint = $this->fingerprintScrapeInputs();

        if ($result->status === ScrapeStatus::Ok) {
            $tier = TierDefaults::for($result->price ?? '0');
            $this->data['drop_threshold_pct'] = (string) $tier['pct'];
            $this->data['drop_threshold_abs'] = (string) $tier['abs'];

            if ($result->currency !== null) {
                $this->data['currency'] = $result->currency;
            }
            if ($result->title !== null) {
                $this->data['title'] = $result->title;
            }
            if ($result->imageUrl !== null) {
                $this->data['image_url'] = $result->imageUrl;
            }

            Notification::make()
                ->title('Preview ok — ' . $result->currency . ' ' . $result->price)
                ->success()
                ->send();
        } elseif ($result->status === ScrapeStatus::NeedsJs) {
            Notification::make()
                ->title('Page renders price via JavaScript')
                ->body('JavaScript-rendered prices are not supported in v1.')
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title('Preview failed: ' . $result->status->value)
                ->body($result->error ?? '')
                ->danger()
                ->send();
        }

        return null;
    }

    private function dataString(string $key): string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<string>
     */
    private function collectFallbackSelectors(): array
    {
        $rows = $this->data['fallback_selectors'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['selector'] ?? null) && $row['selector'] !== '') {
                $out[] = $row['selector'];
            }
        }

        return $out;
    }

    /**
     * Reject save unless the latest preview was successful, fresh, AND its
     * scrape inputs still match the current form data — otherwise we'd persist
     * a baseline price from a URL/selector that no longer reflects what the
     * user is about to save.
     */
    protected function previewIsFreshOk(): bool
    {
        if ($this->previewData === null || $this->previewedAt === null) {
            return false;
        }
        if (($this->previewData['status'] ?? null) !== ScrapeStatus::Ok->value) {
            return false;
        }
        if ($this->previewFingerprint !== $this->fingerprintScrapeInputs()) {
            return false;
        }

        return (Carbon::now()->getTimestamp() - $this->previewedAt) <= self::PREVIEW_TTL_SECONDS;
    }

    /**
     * Stable digest of the form fields that materially affect the scrape
     * result. Edits to any of these invalidate the cached preview.
     */
    private function fingerprintScrapeInputs(): string
    {
        return hash('xxh128', serialize([
            'url' => $this->dataString('url'),
            'price_selector' => $this->dataString('price_selector'),
            'fallback_selectors' => $this->collectFallbackSelectors(),
            'image_selector' => $this->dataString('image_selector'),
            'title_selector' => $this->dataString('title_selector'),
            'currency' => $this->dataString('currency'),
        ]));
    }

    protected function handleRecordCreation(array $data): Model
    {
        if (! $this->previewIsFreshOk()) {
            Notification::make()
                ->title('Preview required')
                ->body('Run a successful preview within the last 5 minutes before saving.')
                ->warning()
                ->send();

            $this->halt();
        }

        /** @var array<string, mixed> $preview */
        $preview = $this->previewData;
        $now = Carbon::now();

        return DB::transaction(function () use ($data, $preview, $now): Product {
            /** @var array<int, array{selector?: string}> $fallbackRows */
            $fallbackRows = $data['fallback_selectors'] ?? [];
            $fallbacks = array_values(array_filter(array_map(
                fn (array $row): string => $row['selector'] ?? '',
                $fallbackRows,
            ), fn (string $s): bool => $s !== ''));

            $product = Product::query()->create([
                'user_id' => auth()->id(),
                'url' => $data['url'],
                'title' => $data['title'],
                'image_url' => $data['image_url'] !== '' ? ($data['image_url'] ?? null) : null,
                'price_selector' => $data['price_selector'],
                'fallback_selectors' => $fallbacks,
                'image_selector' => empty($data['image_selector']) ? null : $data['image_selector'],
                'title_selector' => empty($data['title_selector']) ? null : $data['title_selector'],
                'currency' => $data['currency'],
                'initial_price' => $preview['price'],
                'initial_checked_at' => $now,
                'last_price' => $preview['price'],
                'last_checked_at' => $now,
                'last_success_at' => $now,
                'last_status' => ScrapeStatus::Ok,
                'drop_threshold_pct' => $data['drop_threshold_pct'],
                'drop_threshold_abs' => $data['drop_threshold_abs'],
                'active' => true,
                'needs_js' => false,
            ]);

            PriceCheck::query()->create([
                'product_id' => $product->id,
                'price' => $preview['price'],
                'currency' => $preview['currency'] ?? $data['currency'],
                'raw' => $preview['rawPrice'] ?? null,
                'status' => ScrapeStatus::Ok,
                'checked_at' => $now,
            ]);

            return $product;
        });
    }
}

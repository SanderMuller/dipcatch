<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Schemas;

use App\Models\Product;
use App\Models\Shop;
use App\Support\Iso4217;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Product')
                    ->schema([
                        TextInput::make('title')
                            ->maxLength(255)
                            ->required(),

                        TextInput::make('image_url')
                            ->label('Image URL')
                            ->url()
                            ->maxLength(2048)
                            ->helperText(fn (?Product $record): ?string => $record instanceof Product
                                && ! self::hasShopImages($record)
                                    ? 'Shop images appear here after the next price check of each shop.'
                                    : null)
                            ->suffixAction(self::pickShopImageAction()),
                    ])
                    ->columns(1),

                Section::make('Pricing & alerts')
                    ->description('Shops are managed separately from the product page.')
                    ->schema([
                        Select::make('currency')
                            ->options(Iso4217::options())
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

                        Toggle::make('active')
                            ->label('Tracking active'),
                    ])
                    ->columns(),
            ]);
    }

    private static function pickShopImageAction(): Action
    {
        return Action::make('pickShopImage')
            ->label('Pick from shops')
            ->icon(Heroicon::Photo)
            ->modalHeading('Pick a shop image')
            ->modalSubmitActionLabel('Use this image')
            ->visible(fn (?Product $record): bool => $record instanceof Product
                && self::hasShopImages($record))
            ->schema(fn (?Product $record): array => [
                Select::make('image_url')
                    ->label('Detected images')
                    ->options(self::shopImageOptions($record))
                    ->allowHtml()
                    ->native(false)
                    ->required(),
            ])
            ->action(function (array $data, Set $set): void {
                $picked = $data['image_url'] ?? null;

                if (is_string($picked) && $picked !== '') {
                    $set('image_url', $picked);
                }
            });
    }

    private static function hasShopImages(Product $record): bool
    {
        return $record->shops->contains(fn (Shop $shop): bool => $shop->safeImageUrl() !== null);
    }

    /**
     * Distinct detected images across the product's shops, keyed by URL.
     *
     * @return array<string, string>
     */
    public static function shopImageOptions(?Product $record): array
    {
        if (! $record instanceof Product) {
            return [];
        }

        /** @var array<string, list<string>> $hostsByImage */
        $hostsByImage = [];

        foreach ($record->shops as $shop) {
            $url = $shop->safeImageUrl();

            if ($url === null) {
                continue;
            }

            $host = (string) $shop->host;

            if (! in_array($host, $hostsByImage[$url] ?? [], true)) {
                $hostsByImage[$url][] = $host;
            }
        }

        $options = [];

        foreach ($hostsByImage as $url => $hosts) {
            sort($hosts);
            $options[$url] = self::optionLabel($url, implode(', ', $hosts));
        }

        return $options;
    }

    /**
     * Inline styles, not utility classes: the panel stylesheet ships no
     * arbitrary app utilities, so a sized-by-class thumbnail renders full
     * size inside the dropdown.
     */
    private static function optionLabel(string $url, string $hosts): string
    {
        return '<span style="display:flex;align-items:center;gap:0.5rem;">'
            . '<img src="' . e($url) . '" alt="" loading="lazy"'
            . ' style="height:2.5rem;width:2.5rem;flex:none;object-fit:contain;border-radius:0.25rem;background:#fff;" />'
            . '<span>' . e($hosts) . '</span>'
            . '</span>';
    }
}

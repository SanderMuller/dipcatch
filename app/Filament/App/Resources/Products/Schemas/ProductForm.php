<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Schemas;

use App\Rules\ValidCssSelector;
use App\Support\Iso4217;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Source')
                    ->schema([
                        TextInput::make('url')
                            ->label('Product URL')
                            ->url()
                            ->maxLength(2048)
                            ->required(),

                        TextInput::make('title')
                            ->maxLength(255)
                            ->required(),

                        TextInput::make('image_url')
                            ->label('Image URL')
                            ->url()
                            ->maxLength(2048),
                    ])
                    ->columns(1),

                Section::make('Selectors')
                    ->schema([
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
                    ])
                    ->columns(1),

                Section::make('Pricing & alerts')
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
                    ->columns(2),
            ]);
    }
}

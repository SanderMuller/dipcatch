<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Schemas;

use App\Support\Iso4217;
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
                Section::make('Product')
                    ->schema([
                        TextInput::make('title')
                            ->maxLength(255)
                            ->required(),

                        TextInput::make('image_url')
                            ->label('Image URL')
                            ->url()
                            ->maxLength(2048),
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
}

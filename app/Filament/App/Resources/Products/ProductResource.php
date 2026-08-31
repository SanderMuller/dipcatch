<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products;

use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Filament\App\Resources\Products\Pages\CreateProductManual;
use App\Filament\App\Resources\Products\Pages\EditProduct;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Filament\App\Resources\Products\Pages\ViewProduct;
use App\Filament\App\Resources\Products\RelationManagers\ShopsRelationManager;
use App\Filament\App\Resources\Products\Schemas\ProductForm;
use App\Filament\App\Resources\Products\Schemas\ProductInfolist;
use App\Filament\App\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    /**
     * Scope every Filament query for this resource to the current user.
     * `ProductPolicy` re-checks ownership on each individual record action
     * as a second line of defense.
     *
     * @return EloquentQueryBuilder<Product>
     */
    public static function getEloquentQuery(): EloquentQueryBuilder
    {
        /** @var EloquentQueryBuilder<Product> $query */
        $query = parent::getEloquentQuery();

        return $query->where('user_id', auth()->id());
    }

    /**
     * @return list<class-string>
     */
    public static function getRelations(): array
    {
        return [
            ShopsRelationManager::class,
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'create-manual' => CreateProductManual::route('/create-manual'),
            'view' => ViewProduct::route('/{record}'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}

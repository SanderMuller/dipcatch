<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Pages;

use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Manual fallback for the URL-first create flow — collects product
 * metadata by hand for shops the fetcher cannot reach. Hidden from
 * navigation; linked from the CreateProduct page.
 */
class CreateProductManual extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected static ?string $title = 'Create product manually';

    protected function handleRecordCreation(array $data): Model
    {
        return Product::query()->create([
            'user_id' => auth()->id(),
            'title' => $data['title'] ?? '',
            'image_url' => is_string($data['image_url'] ?? null) && $data['image_url'] !== ''
                ? $data['image_url']
                : null,
            'currency' => $data['currency'] ?? 'EUR',
            'drop_threshold_pct' => $data['drop_threshold_pct'] ?? null,
            'drop_threshold_abs' => $data['drop_threshold_abs'] ?? null,
            'active' => (bool) ($data['active'] ?? true),
        ]);
    }
}

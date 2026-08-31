<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Pages;

use App\Filament\App\Resources\Products\ProductResource;
use Filament\Resources\Pages\Page;

/**
 * URL-first product creation. Hosts the CreateProductFromUrl Livewire
 * component: paste a URL, probe fills title/image/price, tier defaults
 * prefill thresholds, one Confirm creates product + first shop. The
 * manual metadata form lives on CreateProductManual as a fallback.
 */
class CreateProduct extends Page
{
    protected static string $resource = ProductResource::class;

    protected static ?string $title = 'Track a new product';

    protected string $view = 'filament.app.pages.create-product';
}

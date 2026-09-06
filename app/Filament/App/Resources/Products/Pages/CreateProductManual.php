<?php declare(strict_types=1);

namespace App\Filament\App\Resources\Products\Pages;

use App\Billing\PlanLimitReached;
use App\Billing\PlanLimits;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new Halt();
        }

        try {
            return DB::transaction(function () use ($user, $data): Model {
                app(PlanLimits::class)->guardProduct($user);

                return Product::query()->create([
                    'user_id' => $user->id,
                    'title' => $data['title'] ?? '',
                    'image_url' => is_string($data['image_url'] ?? null) && $data['image_url'] !== ''
                        ? $data['image_url']
                        : null,
                    'currency' => $data['currency'] ?? 'EUR',
                    'drop_threshold_pct' => $data['drop_threshold_pct'] ?? null,
                    'drop_threshold_abs' => $data['drop_threshold_abs'] ?? null,
                    'active' => (bool) ($data['active'] ?? true),
                ]);
            });
        } catch (PlanLimitReached $e) {
            Notification::make()
                ->warning()
                ->title('Plan limit reached')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            throw new Halt();
        }
    }
}

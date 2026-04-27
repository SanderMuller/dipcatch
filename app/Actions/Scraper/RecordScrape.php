<?php declare(strict_types=1);

namespace App\Actions\Scraper;

use App\Enums\ScrapeStatus;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Services\Scraper\ScrapeResult;
use Illuminate\Support\Facades\DB;

class RecordScrape
{
    /**
     * Persist a scrape result: insert a `price_checks` row and update the
     * product's `last_*` columns. `last_price` is preserved when the scrape
     * did not yield a valid price, so transient failures don't corrupt the
     * price-history series.
     */
    public function __invoke(Product $product, ScrapeResult $result): PriceCheck
    {
        return DB::transaction(function () use ($product, $result): PriceCheck {
            $checkedAt = now();

            $check = PriceCheck::query()->create([
                'product_id' => $product->id,
                'price' => $result->price,
                'currency' => $result->currency,
                'raw' => $result->rawPrice,
                'status' => $result->status,
                'error' => $result->error,
                'checked_at' => $checkedAt,
            ]);

            $updates = [
                'last_status' => $result->status,
                'last_error' => $result->error,
                'last_checked_at' => $checkedAt,
            ];

            if ($result->status === ScrapeStatus::Ok) {
                $updates['last_price'] = $result->price;
                $updates['last_success_at'] = $checkedAt;
                $updates['needs_js'] = false;
            } elseif ($result->status === ScrapeStatus::NeedsJs) {
                $updates['needs_js'] = true;
            }

            $product->forceFill($updates)->save();

            return $check;
        });
    }
}

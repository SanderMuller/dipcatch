<?php declare(strict_types=1);

namespace Database\Factories;

use App\Analytics\Enums\DeviceClass;
use App\Analytics\Enums\PageViewSection;
use App\Analytics\Models\PageView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageView>
 */
final class PageViewFactory extends Factory
{
    protected $model = PageView::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            PageView::VIEWED_AT => now(),
            PageView::SECTION => PageViewSection::Marketing,
            PageView::ROUTE_NAME => 'home',
            PageView::PATH => '/',
            PageView::REFERRER_HOST => null,
            PageView::UTM_SOURCE => null,
            PageView::UTM_MEDIUM => null,
            PageView::UTM_CAMPAIGN => null,
            PageView::COUNTRY => 'NL',
            PageView::DEVICE => DeviceClass::Desktop,
            PageView::IS_AUTHENTICATED => false,
            PageView::USER_ID => null,
            PageView::VISITOR_HASH => fake()->md5(),
            PageView::VISITOR_ID => null,
        ];
    }

    public function anonymous(): static
    {
        return $this->state([PageView::USER_ID => null, PageView::IS_AUTHENTICATED => false]);
    }

    public function linked(?User $user = null): static
    {
        return $this->state(fn (): array => [
            PageView::SECTION => PageViewSection::App,
            PageView::ROUTE_NAME => 'app.dashboard',
            PageView::PATH => '/app',
            PageView::IS_AUTHENTICATED => true,
            PageView::USER_ID => $user ?? User::factory(),
        ]);
    }
}

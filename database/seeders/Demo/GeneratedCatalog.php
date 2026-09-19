<?php declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\CategorySource;
use App\Enums\ProductCategory;

/**
 * Bulk demo products, so a local checkout shows a populated account rather
 * than a handful of rows: pagination, search, sorting and the plan-limit
 * counters all need more products than a hand-written catalog carries.
 *
 * The curated catalogs in `DemoSeeder` stay the place where an edge state is
 * pinned (dead, failing, sold out, conditional price, promotion, paused).
 * These rows are the ordinary middle: a price, a few offers, some history.
 *
 * The pool is walked in order rather than sampled, so the only randomness is
 * `fake()`, which `DemoSeeder` seeds before it calls this. Called anywhere
 * else, these rows are no longer reproducible.
 */
final readonly class GeneratedCatalog
{
    /** Shorter than the curated catalog: many products, not many rows each. */
    private const int HISTORY_DAYS = 30;

    /**
     * @return list<DemoProduct>
     */
    public static function make(int $count, int $offset = 0): array
    {
        $pool = self::pool();
        $products = [];

        for ($index = 0; $index < $count; $index++) {
            // The position drives every variation below through a modulus, so
            // it must start at one: zero satisfies all of them at once, and
            // the first row of every account would be paused, dropped,
            // discounted, on promotion and part sold out.
            $position = $offset + $index + 1;
            $products[] = self::product($pool[$position % count($pool)], $position);
        }

        return $products;
    }

    /**
     * @param  array{0: string, 1: float, 2: float, 3: string, 4: list<string>, 5: ProductCategory}  $item
     */
    private static function product(array $item, int $position): DemoProduct
    {
        [$title, $price, $packQuantity, $packUnit, $hosts, $category] = $item;

        $offerCount = min(count($hosts), fake()->numberBetween(1, 3));
        $offers = [];

        foreach (array_slice($hosts, 0, $offerCount) as $offerIndex => $host) {
            $offers[] = new DemoOffer(
                host: $host,
                path: self::path($host, $title, $position),
                price: round($price * (1 + $offerIndex * fake()->randomFloat(3, -0.06, 0.09)), 2),
                packQuantity: $packQuantity,
                packUnit: $packUnit,
                // Never the first offer: a product whose every offer is out
                // of stock has no cheapest price, and then nothing downstream
                // — chart, drop, unit price — has a number to show.
                state: $offerIndex === 0 ? 'ok' : self::state($position),
                conditional: $position % 17 === 0,
                promotion: $position % 13 === 0 ? 'live' : null,
            );
        }

        return new DemoProduct(
            title: $title,
            offers: $offers,
            active: $position % 19 !== 0,
            drop: match (true) {
                $position % 11 === 0 => 'today',
                $position % 7 === 0 => 'recent',
                $position % 5 === 0 => 'week',
                default => null,
            },
            historyDays: self::HISTORY_DAYS,
            ageDays: fake()->numberBetween(self::HISTORY_DAYS + 2, 240),
            // Every ninth stays unsorted; an account never has every product placed.
            category: $position % 9 === 0 ? null : $category,
            categorySource: CategorySource::Auto,
        );
    }

    /**
     * Only the ordinary states here. A failing or a dead offer keeps its
     * curated home, where the catalog says out loud which one it is.
     */
    private static function state(int $position): string
    {
        return match (true) {
            $position % 23 === 0 => 'out_of_stock',
            $position % 29 === 0 => 'unknown_stock',
            default => 'ok',
        };
    }

    private static function path(string $host, string $title, int $position): string
    {
        $slug = str($title)->slug()->value();

        return match ($host) {
            'ah.nl' => 'producten/product/wi' . (400000 + $position) . '/' . $slug,
            'jumbo.com' => 'producten/' . $slug . '-' . (500000 + $position),
            'bol.com' => 'nl/nl/p/' . $slug . '/' . (9300000000000000 + $position),
            'coolblue.nl' => 'product/' . (940000 + $position) . '/' . $slug . '.html',
            'amazon.nl' => 'dp/B0' . str_pad((string) $position, 8, '0', STR_PAD_LEFT),
            'zooplus.nl' => 'shop/' . $slug . '/' . (600000 + $position),
            default => 'boodschappen/' . $slug . '/' . (700000 + $position),
        };
    }

    /**
     * Real products on real Dutch retailers, with plausible prices and pack
     * sizes — the unit-price column is meaningless on invented quantities.
     *
     * @return list<array{0: string, 1: float, 2: float, 3: string, 4: list<string>, 5: ProductCategory}>
     */
    private static function pool(): array
    {
        return [
            ['Lavazza Qualità Oro koffiebonen 1 kg', 18.99, 1000, 'g', ['ah.nl', 'bol.com', 'amazon.nl'], ProductCategory::CoffeeTea],
            ['Pickwick Engelse Melange 20 zakjes', 2.19, 20, 'piece', ['ah.nl', 'jumbo.com'], ProductCategory::CoffeeTea],
            ['Calvé Pindakaas 650 g', 4.49, 650, 'g', ['jumbo.com', 'ah.nl', 'dirk.nl'], ProductCategory::Pantry],
            ['Brinta Original 500 g', 2.79, 500, 'g', ['ah.nl', 'dirk.nl'], ProductCategory::Bakery],
            ['Campina Volle Melk 1,5 L', 1.85, 1500, 'ml', ['jumbo.com', 'ah.nl'], ProductCategory::DairyEggs],
            ['Optimel Drinkyoghurt Aardbei 1 L', 1.55, 1000, 'ml', ['ah.nl', 'dirk.nl'], ProductCategory::DairyEggs],
            ['Beemster Belegen Kaas 500 g', 6.99, 500, 'g', ['jumbo.com', 'ah.nl'], ProductCategory::DairyEggs],
            ['Bertolli Olijfolie Extra Vergine 1 L', 12.49, 1000, 'ml', ['ah.nl', 'bol.com'], ProductCategory::Pantry],
            ['Honig Groentesoep Tomaat 800 ml', 2.09, 800, 'ml', ['jumbo.com', 'dirk.nl'], ProductCategory::Pantry],
            ['Knorr Wereldgerechten Nasi Goreng', 1.89, 1, 'piece', ['ah.nl', 'jumbo.com'], ProductCategory::Pantry],
            ['Conimex Kroepoek Naturel 75 g', 2.29, 75, 'g', ['ah.nl', 'dirk.nl'], ProductCategory::SnacksSweets],
            ['Unox Rookworst 275 g', 3.39, 275, 'g', ['jumbo.com', 'ah.nl'], ProductCategory::MeatFishVeg],
            ['Douwe Egberts Senseo Classic 36 pads', 6.79, 36, 'piece', ['bol.com', 'ah.nl', 'amazon.nl'], ProductCategory::CoffeeTea],
            ['Lipton Ice Tea Green 1,5 L', 2.15, 1500, 'ml', ['jumbo.com', 'dirk.nl'], ProductCategory::SoftDrinks],
            ['Spa Reine Bronwater 6 x 1,5 L', 4.69, 9000, 'ml', ['ah.nl', 'jumbo.com'], ProductCategory::SoftDrinks],
            ['Hertog Jan Pilsener 24 x 30 cl', 19.99, 7200, 'ml', ['ah.nl', 'jumbo.com'], ProductCategory::Alcohol],
            ['Chocomel Vol 1 L', 2.35, 1000, 'ml', ['ah.nl', 'dirk.nl'], ProductCategory::SoftDrinks],
            ['Verkade Milk Chocolade Tablet 111 g', 2.09, 111, 'g', ['jumbo.com', 'ah.nl'], ProductCategory::SnacksSweets],
            ['Haribo Goudberen 1 kg', 8.49, 1000, 'g', ['bol.com', 'amazon.nl'], ProductCategory::SnacksSweets],
            ['Duyvis Gezouten Pinda\'s 500 g', 4.79, 500, 'g', ['ah.nl', 'jumbo.com'], ProductCategory::SnacksSweets],
            ['Ariel Vloeibaar Wasmiddel 60 wasbeurten', 21.99, 60, 'piece', ['bol.com', 'ah.nl', 'amazon.nl'], ProductCategory::Laundry],
            ['Dreft Afwasmiddel Original 800 ml', 3.29, 800, 'ml', ['jumbo.com', 'ah.nl'], ProductCategory::Cleaning],
            ['Finish Quantum Vaatwastabletten 60 stuks', 24.95, 60, 'piece', ['bol.com', 'amazon.nl'], ProductCategory::Cleaning],
            ['Zwitsal Baby Shampoo 400 ml', 3.99, 400, 'ml', ['ah.nl', 'bol.com'], ProductCategory::BabyGear],
            ['Sensodyne Repair & Protect Tandpasta 75 ml', 5.49, 75, 'ml', ['ah.nl', 'bol.com'], ProductCategory::Oral],
            ['Oral-B Pro 3 3000 elektrische tandenborstel', 59.99, 1, 'piece', ['coolblue.nl', 'bol.com', 'amazon.nl'], ProductCategory::Oral],
            ['Gillette Fusion5 scheermesjes 8 stuks', 32.99, 8, 'piece', ['bol.com', 'amazon.nl'], ProductCategory::Shaving],
            ['Nivea Men Deodorant Spray 150 ml', 3.19, 150, 'ml', ['ah.nl', 'bol.com'], ProductCategory::SkinBody],
            ['Page Toiletpapier 24 rollen', 12.99, 24, 'piece', ['jumbo.com', 'bol.com'], ProductCategory::PaperDisposables],
            ['Swiffer Duster Navullingen 18 stuks', 14.49, 18, 'piece', ['bol.com', 'amazon.nl'], ProductCategory::Cleaning],
            ['Philips Airfryer XXL HD9650', 229.00, 1, 'piece', ['coolblue.nl', 'bol.com'], ProductCategory::SmallAppliances],
            ['Senseo Original Koffiepadapparaat', 79.99, 1, 'piece', ['coolblue.nl', 'bol.com'], ProductCategory::SmallAppliances],
            ['Tefal Ingenio Pannenset 5-delig', 89.99, 5, 'piece', ['bol.com', 'coolblue.nl'], ProductCategory::Kitchen],
            ['Anker PowerCore 20000 powerbank', 49.99, 1, 'piece', ['bol.com', 'amazon.nl', 'coolblue.nl'], ProductCategory::Accessories],
            ['Logitech MX Master 3S muis', 109.00, 1, 'piece', ['coolblue.nl', 'bol.com', 'amazon.nl'], ProductCategory::Computers],
            ['Samsung T7 Portable SSD 1 TB', 99.00, 1, 'piece', ['coolblue.nl', 'amazon.nl'], ProductCategory::Computers],
            ['SanDisk Extreme microSDXC 256 GB', 34.99, 1, 'piece', ['bol.com', 'amazon.nl'], ProductCategory::Cameras],
            ['JBL Flip 6 bluetooth speaker', 119.00, 1, 'piece', ['coolblue.nl', 'bol.com'], ProductCategory::Audio],
            ['Kärcher K5 Premium hogedrukreiniger', 329.00, 1, 'piece', ['coolblue.nl', 'bol.com'], ProductCategory::GardenToolsFurniture],
            ['Bosch Professional GSR 12V accuschroevendraaier', 139.00, 1, 'piece', ['bol.com', 'amazon.nl'], ProductCategory::ToolsHardware],
            ['Osram LED Star E14 kaarslamp (4-pack)', 9.99, 4, 'piece', ['bol.com', 'amazon.nl'], ProductCategory::Lighting],
            ['Duracell Plus AA batterijen 16 stuks', 15.49, 16, 'piece', ['bol.com', 'ah.nl'], ProductCategory::Accessories],
            ['Whiskas Droogvoer Kip 1,9 kg', 11.49, 1900, 'g', ['zooplus.nl', 'bol.com'], ProductCategory::PetFood],
            ['Pedigree Dentastix Medium 56 stuks', 17.99, 56, 'piece', ['zooplus.nl', 'bol.com'], ProductCategory::PetFood],
            ['Sanicat Kattenbakvulling Clumping 20 L', 13.99, 20000, 'ml', ['zooplus.nl', 'bol.com'], ProductCategory::PetCare],
            ['Pampers Premium Protection maat 3 (198 stuks)', 49.99, 198, 'piece', ['bol.com', 'amazon.nl'], ProductCategory::NappiesWipes],
            ['Nutrilon Standaard 2 opvolgmelk 800 g', 19.49, 800, 'g', ['bol.com', 'ah.nl'], ProductCategory::BabyFood],
            ['LEGO Classic Creatieve Stenen 11717', 39.99, 1, 'piece', ['bol.com', 'amazon.nl'], ProductCategory::BuildingSets],
            ['Nintendo Switch Pro Controller', 69.99, 1, 'piece', ['coolblue.nl', 'bol.com', 'amazon.nl'], ProductCategory::VideoGames],
            ['Garnier Micellair Reinigingswater 400 ml', 4.99, 400, 'ml', ['ah.nl', 'bol.com'], ProductCategory::SkinBody],
        ];
    }
}

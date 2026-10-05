<?php declare(strict_types=1);

use App\Filament\Admin\Resources\Shops\Pages\ListShops;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->admin()->create());
});

/**
 * @return list<array<string, string>>
 */
function downloadedCsv(string $action): array
{
    $component = livewire(ListShops::class)
        ->callAction($action)
        ->assertFileDownloaded();

    $encoded = data_get($component->effects, 'download.content');
    expect($encoded)->toBeString();

    $lines = array_map(
        fn (string $line): array => array_map(strval(...), str_getcsv($line, escape: '')),
        explode("\n", trim(base64_decode(is_string($encoded) ? $encoded : ''))),
    );
    $header = array_shift($lines) ?? [];

    return array_map(fn (array $line): array => array_combine($header, $line), $lines);
}

test('the hosts export counts offers and accounts per host without owner data', function (): void {
    $owner = User::factory()->create(['email' => 'jane.doe@example.test']);
    $otherOwner = User::factory()->create();

    Shop::factory()->create([
        'product_id' => Product::factory()->for($owner),
        'url' => 'https://www.winkel.example.de/p/kaffee',
        'notes' => 'Private admin note',
    ]);
    Shop::factory()->create([
        'product_id' => Product::factory()->for($otherOwner),
        'url' => 'https://winkel.example.de/p/kaffee',
    ]);
    Shop::factory()->dead()->create([
        'product_id' => Product::factory()->for($owner),
        'url' => 'https://winkel.example.de/p/tee',
    ]);

    $rows = downloadedCsv('export_hosts');

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'host' => 'winkel.example.de',
            'tld' => 'de',
            'support' => 'generic',
            'offers' => '3',
            'distinct_urls' => '2',
            'accounts' => '2',
            'health_ok' => '2',
            'health_dead' => '1',
        ])
        ->and(implode(',', array_merge(...array_map(array_values(...), $rows))))
        ->not->toContain('jane.doe@example.test')
        ->not->toContain('Private admin note');
});

test('the product URLs export folds one page into one row and drops the query string', function (): void {
    $owner = User::factory()->create(['email' => 'jane.doe@example.test']);

    Shop::factory()->create([
        'product_id' => Product::factory()->for($owner)->state(['title' => 'Jane her secret gift']),
        'url' => 'https://winkel.example.de/p/kaffee?ref=jane123#reviews',
    ]);
    Shop::factory()->dead()->create([
        'url' => 'https://winkel.example.de/p/kaffee?ref=jane123#reviews',
        'last_checked_at' => now()->subDay(),
    ]);

    $rows = downloadedCsv('export_urls');

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'host' => 'winkel.example.de',
            'url' => 'https://winkel.example.de/p/kaffee',
            'offers' => '2',
            'health' => 'ok',
        ])
        ->and(implode(',', $rows[0]))
        ->not->toContain('jane')
        ->not->toContain('secret gift');
});

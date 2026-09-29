$user = App\Models\User::where('email', 'demo@dipcatch.test')->firstOrFail();
$user->notifications()->where('type', 'eye-verify-reference-shops')->delete();
$user->notifications()->create([
    'id' => (string) Illuminate\Support\Str::uuid(),
    'type' => 'eye-verify-reference-shops',
    'data' => [
        'title' => 'Eye-verify: Lungo XL',
        'new_price' => '12.99',
        'currency' => 'EUR',
        'host' => 'amazon.nl',
        'also_check' => [
            ['host' => 'koffiehenk.nl', 'url' => 'https://www.koffiehenk.nl/dolce-gusto-lungo-xl'],
            ['host' => 'bol.com', 'url' => 'https://www.bol.com/nl/nl/p/x/1/'],
        ],
    ],
]);
echo 'seeded one alert carrying two links', PHP_EOL;

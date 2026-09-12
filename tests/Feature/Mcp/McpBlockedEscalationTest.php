<?php declare(strict_types=1);

use App\Mcp\Servers\DipCatchServer;
use App\Mcp\Tools\CreateProductTool;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('four consecutive blocked calls escalate, with nothing cleared between them', function (): void {
    Cache::flush();

    Http::fake([
        'https://www.pharmapets.nl/robots.txt' => Http::response('', 404),
        'https://www.pharmapets.nl/*' => Http::response('<html>Forbidden</html>', 403),
    ]);

    $me = User::factory()->create();
    $url = 'https://www.pharmapets.nl/sanimed-atopy-sensitive-kattenvoer-maaltijdzakjes-12x-100g.html';

    $messages = [];

    foreach (range(1, 4) as $i) {
        $response = DipCatchServer::actingAs($me)->tool(CreateProductTool::class, ['url' => $url]);

        try {
            $response->assertSee('Retrying will not help');
            $messages[] = "call {$i}: escalated";
        } catch (Throwable) {
            $messages[] = "call {$i}: plain";
        }
    }

    expect($messages)->toBe([
        'call 1: plain',
        'call 2: plain',
        'call 3: escalated',
        'call 4: escalated',
    ]);
});

<?php declare(strict_types=1);

use App\Models\User;
use Laravel\Passport\Passport;

/**
 * @return array<string, array{title: string, readOnlyHint: bool, destructiveHint: bool, openWorldHint: bool}>
 */
function expectedToolAnnotations(): array
{
    return [
        'list_products' => [
            'title' => 'List products',
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'openWorldHint' => false,
        ],
        'get_product' => [
            'title' => 'Get product',
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'openWorldHint' => false,
        ],
        'price_history' => [
            'title' => 'Price history',
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'openWorldHint' => false,
        ],
        'create_product' => [
            'title' => 'Create product',
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'openWorldHint' => false,
        ],
        'add_shop' => [
            'title' => 'Add shop',
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'openWorldHint' => false,
        ],
        'recheck' => [
            'title' => 'Recheck prices',
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'openWorldHint' => false,
        ],
        'set_threshold' => [
            'title' => 'Set threshold',
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'openWorldHint' => false,
        ],
        'remove_shop' => [
            'title' => 'Remove shop',
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'openWorldHint' => false,
        ],
        'delete_product' => [
            'title' => 'Delete product',
            'readOnlyHint' => false,
            'destructiveHint' => true,
            'openWorldHint' => false,
        ],
    ];
}

it('advertises titles and hint values OpenAI scan tools can read', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use']);

    $listed = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertOk()->json('result.tools');

    if (! is_array($listed)) {
        $this->fail('tools/list did not return a tools array');
    }

    $tools = collect($listed)->keyBy('name');

    $expected = expectedToolAnnotations();

    expect($tools)->toHaveSameSize($expected);

    foreach ($expected as $name => $hints) {
        expect($tools->get($name))->not->toBeNull()
            ->and($tools[$name]['title'])->toBe($hints['title'])
            ->and($tools[$name]['annotations']['readOnlyHint'])->toBe($hints['readOnlyHint'])
            ->and($tools[$name]['annotations']['destructiveHint'])->toBe($hints['destructiveHint'])
            ->and($tools[$name]['annotations']['openWorldHint'])->toBe($hints['openWorldHint']);
    }
});

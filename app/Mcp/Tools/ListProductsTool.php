<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\InteractsWithOwner;
use App\Mcp\Support\ProductPresenter;
use App\Models\Product;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_products')]
#[Title('List products')]
#[Description('Lists every product this user tracks, with its current cheapest price and how many shops it has.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListProductsTool extends Tool
{
    use InteractsWithOwner;

    public function __construct(private readonly ProductPresenter $presenter) {}

    public function handle(Request $request): ResponseFactory
    {
        $products = Product::query()
            ->where('user_id', $this->user($request)->getKey())
            ->orderBy('title')
            ->get();

        return Response::structured([
            'products' => $products->map($this->presenter->summary(...))->all(),
        ]);
    }
}

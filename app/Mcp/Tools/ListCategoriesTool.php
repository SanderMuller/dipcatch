<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\ProductCategory;
use App\Enums\ProductDepartment;
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

#[Name('list_categories')]
#[Title('List categories')]
#[Description('The product categories DipCatch knows, grouped by department. Pass a department key or a category key to list_products, and a category key to create_product.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class ListCategoriesTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $departments = [];

        foreach (ProductDepartment::cases() as $department) {
            $departments[] = [
                'key' => $department->value,
                'label' => $department->label(),
                'categories' => array_map(static fn (ProductCategory $category): array => [
                    'key' => $category->value,
                    'label' => $category->label(),
                ], $department->categories()),
            ];
        }

        return Response::structured(['departments' => $departments]);
    }
}

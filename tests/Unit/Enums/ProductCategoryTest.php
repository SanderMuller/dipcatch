<?php declare(strict_types=1);

use App\Enums\ProductCategory;
use App\Enums\ProductDepartment;

test('every category belongs to exactly one department', function (): void {
    foreach (ProductCategory::cases() as $category) {
        $owners = array_filter(
            ProductDepartment::cases(),
            static fn (ProductDepartment $department): bool => in_array($category, $department->categories(), strict: true),
        );

        expect($owners)->toHaveCount(1)
            ->and(array_first($owners))->toBe($category->department());
    }
});

test('grouped() lists every category once, under its own department', function (): void {
    $grouped = ProductCategory::grouped();

    expect(array_keys($grouped))->toBe(array_map(static fn (ProductDepartment $d): string => $d->value, ProductDepartment::cases()));

    $seen = [];

    foreach ($grouped as $departmentValue => $categories) {
        foreach ($categories as $category) {
            expect($category->department()->value)->toBe($departmentValue);
            $seen[] = $category->value;
        }
    }

    expect($seen)->toHaveSameSize(ProductCategory::cases())
        ->and(array_unique($seen))->toHaveSameSize(ProductCategory::cases());
});

test('values() lists every category value', function (): void {
    expect(ProductCategory::values())
        ->toHaveSameSize(ProductCategory::cases())
        ->toContain('food.coffee_tea', 'other.other');
});

test('leafKey() is the part a leaf Choice answers with', function (): void {
    expect(ProductCategory::CoffeeTea->leafKey())->toBe('coffee_tea')
        ->and(ProductCategory::CoffeeTea->department())->toBe(ProductDepartment::Food);
});

test('leavesFor() expands a department key to every category in it', function (): void {
    expect(ProductCategory::leavesFor('pets'))->toBe(['pets.pet_food', 'pets.pet_care', 'pets.pet_accessories']);
});

test('leavesFor() returns the one category for a category key', function (): void {
    expect(ProductCategory::leavesFor('food.coffee_tea'))->toBe(['food.coffee_tea']);
});

test('leavesFor() returns null for an unknown, empty or missing key', function (?string $value): void {
    expect(ProductCategory::leavesFor($value))->toBeNull();
})->with(['nonsense', 'food.nonsense', '', null]);

test('real() leaves Other out of the departments a leaf question is asked for', function (): void {
    expect(ProductDepartment::real())
        ->toHaveCount(count(ProductDepartment::cases()) - 1)
        ->not->toContain(ProductDepartment::Other);
});

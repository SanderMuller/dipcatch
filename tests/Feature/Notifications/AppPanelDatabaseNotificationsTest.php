<?php declare(strict_types=1);

use Filament\Facades\Filament;

test('AppPanel enables database notifications so the bell renders', function (): void {
    expect(Filament::getPanel('app')->hasDatabaseNotifications())->toBeTrue();
});

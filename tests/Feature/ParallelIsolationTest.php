<?php declare(strict_types=1);

use Illuminate\Support\Facades\ParallelTesting;

/**
 * The suite runs with `--parallel`, and the workers share one Redis server.
 * Setting the prefix on the application that runs `setUpProcess` is not
 * enough: each test builds a fresh application, which reloads config from
 * the files. The environment is what survives, so this asserts the prefix
 * actually reaches a booted worker application.
 */
test('each parallel worker gets its own Redis prefix', function (): void {
    $token = ParallelTesting::token();

    if ($token === false) {
        $this->markTestSkipped('Only meaningful under --parallel.');
    }

    expect(config('database.redis.options.prefix'))->toBe('dipcatch-test-' . $token . '-');
});

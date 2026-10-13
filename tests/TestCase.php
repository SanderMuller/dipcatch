<?php declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Sleep;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * No test waits for real. An HTTP retry sleeps two seconds between tries,
     * and the suite has a dozen tests that drive one. A test that checks the
     * pause asserts it with `Sleep::assertSlept()`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

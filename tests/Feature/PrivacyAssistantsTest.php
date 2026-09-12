<?php declare(strict_types=1);

it('tells readers what a connected assistant receives', function (): void {
    $this->get(route('privacy'))
        ->assertOk()
        ->assertSee('An assistant you connect')
        ->assertSee('DipCatch does not send your password to the assistant.');
});

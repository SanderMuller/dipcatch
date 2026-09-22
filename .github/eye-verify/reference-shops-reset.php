<?php
// Removes only the link this eye-verify run creates, so the flow can be driven
// again from the same starting state. Never touches a tracked shop.
$removed = App\Models\Shop::query()
    ->where('kind', App\Enums\ShopKind::Reference->value)
    ->where('host', 'like', 'this-host-does-not-resolve%')
    ->delete();

echo "removed {$removed} eye-verify link(s)", PHP_EOL;

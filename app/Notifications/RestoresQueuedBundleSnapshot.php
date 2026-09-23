<?php declare(strict_types=1);

namespace App\Notifications;

use App\PriceAdapters\BundleOffer;

/**
 * Rebuild `$snapshotBundle` from the flat pair an older payload carries, and
 * give every snapshot added since a null default.
 *
 * The three alert notifications are `ShouldQueue`, so one dispatched before
 * `snapshotBundleQuantity` and `snapshotBundleTotalPrice` became a single
 * `BundleOffer` comes back off the queue with neither property present. The
 * typed property would then stay uninitialized, and the first read inside
 * `toDatabase()` or `toWebPush()` throws. A retry deserializes the same
 * payload, so the job fails for good and the bell row and the push are lost.
 *
 * Delete the bundle branch once no payload predating that change can still be
 * queued. Every snapshot property added later belongs in LATER_SNAPSHOTS.
 */
trait RestoresQueuedBundleSnapshot
{
    /** Snapshot properties that are null on a payload queued before they existed. */
    private const array LATER_SNAPSHOTS = [
        'snapshotPackQuantity',
        'snapshotPackUnit',
        'snapshotUnit',
        'snapshotUnitPrice',
        'snapshotBetterValueHost',
        'snapshotBetterValueUnitPrice',
        'snapshotTargetPrice',
    ];

    /**
     * Fold a legacy payload into the current shape, then hand it to
     * `SerializesModels`, which still owns restoring the models in it.
     *
     * @param  array<string, mixed>  $values
     */
    public function __unserialize(array $values): void
    {
        if (! array_key_exists('snapshotBundle', $values)) {
            // `stored()` re-applies the cheaper-than test the pair was
            // written under, so a legacy payload cannot revive an offer the
            // current code would reject.
            $single = $values['snapshotSingleItemPrice'] ?? null;

            $values['snapshotBundle'] = BundleOffer::stored(
                $values['snapshotBundleQuantity'] ?? null,
                $values['snapshotBundleTotalPrice'] ?? null,
                is_string($single) ? $single : null,
            );

            unset($values['snapshotBundleQuantity'], $values['snapshotBundleTotalPrice']);
        }

        // Snapshots added after a payload was queued. A missing key would leave
        // the typed property uninitialized, which throws on first read; null
        // renders the line these payloads were written for.
        foreach (self::LATER_SNAPSHOTS as $name) {
            $values[$name] ??= null;
        }

        parent::__unserialize($values);
    }
}

<?php declare(strict_types=1);

namespace App\PriceAdapters;

/**
 * The forms a schema.org type and entity take beyond the plain shape
 * {@see JsonLdEntities} walks: a type written as a full URL, and a Product
 * stated only as the `object` of an Action.
 */
final class JsonLdTypeForms
{
    /** `https://schema.org/Product` → `Product`; anything else unchanged. */
    public static function short(mixed $type): mixed
    {
        return is_string($type) ? (preg_replace('~^https?://schema\.org/~i', '', $type) ?? $type) : $type;
    }

    /**
     * Whether every Product or ProductGroup type the entity states is written
     * as a full schema.org URL. A page that writes only that form went to the
     * OpenGraph reader before `typesOf()` learned it, and keeps doing so when
     * its Product carries no offer.
     *
     * @param  array<string, mixed>  $entity
     */
    public static function namesProductOnlyByUrl(array $entity): bool
    {
        $type = $entity['@type'] ?? null;
        $named = array_filter(
            is_array($type) ? array_values($type) : [$type],
            static fn (mixed $entry): bool => in_array(self::short($entry), ['Product', 'ProductGroup'], strict: true),
        );

        return $named !== [] && array_all($named, static fn (mixed $entry): bool => is_string($entry) && $entry !== self::short($entry));
    }

    /**
     * Products a page states only as the `object` of an Action, one level
     * deep: MediaMarkt publishes `{"@type": "BuyAction", "object": {Product}}`.
     *
     * @return list<array<string, mixed>>
     */
    public static function actionObjects(mixed $decoded): array
    {
        $objects = [];

        foreach (JsonLdEntities::expandGraph($decoded) as $entity) {
            $object = $entity['object'] ?? null;
            $isAction = array_any(JsonLdEntities::typesOf($entity), static fn (mixed $type): bool => is_string($type) && str_ends_with($type, 'Action'));

            if ($isAction && is_array($object) && ! array_is_list($object)) {
                /** @var array<string, mixed> $object */
                $objects[] = $object;
            }
        }

        return $objects;
    }
}

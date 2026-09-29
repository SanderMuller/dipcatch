<?php declare(strict_types=1);

namespace App\Enums;

/**
 * Things people buy again and again, offered as a getting-started checklist.
 *
 * An idea is ticked by a tracked product in one of its categories, or whose
 * title matches its keywords. Keywords carry the free accounts, whose
 * products have no category, and the ideas narrower than their category:
 * deodorant, sunscreen and skincare are all `health.skin_body`, and a storage
 * box, a floor lamp, a perfume, a houseplant or a dashcam is not bin bags,
 * light bulbs, skincare, potting soil or AdBlue. Those tick on words only.
 * Groceries has no words: too many to list, so a free account ticks it by
 * hand. An account with automatic categories also gets the idea Jev picked,
 * stored on the product as `tracking_idea`.
 */
enum TrackingIdea: string
{
    case Groceries = 'groceries';
    case CoffeeTea = 'coffee_tea';
    case SoftDrinks = 'soft_drinks';
    case BeerWine = 'beer_wine';
    case SportsNutrition = 'sports_nutrition';

    case Paper = 'paper';
    case Cleaning = 'cleaning';
    case Laundry = 'laundry';
    case Dishwasher = 'dishwasher';
    case BinBags = 'bin_bags';
    case VacuumBags = 'vacuum_bags';
    case Batteries = 'batteries';
    case WaterFilters = 'water_filters';
    case LightBulbs = 'light_bulbs';
    case Candles = 'candles';
    case PrinterInk = 'printer_ink';

    case DeodorantShower = 'deodorant_shower';
    case HairCare = 'hair_care';
    case OralCare = 'oral_care';
    case Shaving = 'shaving';
    case Sunscreen = 'sunscreen';
    case SkincareMakeup = 'skincare_makeup';
    case ContactLenses = 'contact_lenses';
    case Sanitary = 'sanitary';

    case VitaminsMedicine = 'vitamins_medicine';
    case Nappies = 'nappies';
    case BabyFood = 'baby_food';

    case PetFood = 'pet_food';
    case CatLitter = 'cat_litter';
    case FleaTreatment = 'flea_treatment';

    case Garden = 'garden';
    case Car = 'car';
    /** No category or word names a hobby: only Jev or the person ticks it. */
    case Hobby = 'hobby';

    public function label(): string
    {
        return match ($this) {
            self::Groceries => __('Groceries'),
            self::CoffeeTea => __('Coffee and tea'),
            self::SoftDrinks => __('Soft drinks and water'),
            self::BeerWine => __('Beer and wine'),
            self::SportsNutrition => __('Sports nutrition'),
            self::Paper => __('Toilet and kitchen paper'),
            self::Cleaning => __('Cleaning products'),
            self::Laundry => __('Laundry detergent'),
            self::Dishwasher => __('Dishwasher tablets and dish soap'),
            self::BinBags => __('Bin bags'),
            self::VacuumBags => __('Vacuum cleaner bags and filters'),
            self::Batteries => __('Batteries'),
            self::WaterFilters => __('Water filters and descaler'),
            self::LightBulbs => __('Light bulbs'),
            self::Candles => __('Candles and air fresheners'),
            self::PrinterInk => __('Printer ink'),
            self::DeodorantShower => __('Deodorant and shower gel'),
            self::HairCare => __('Shampoo and hair care'),
            self::OralCare => __('Toothpaste and toothbrush heads'),
            self::Shaving => __('Razor blades'),
            self::Sunscreen => __('Sunscreen'),
            self::SkincareMakeup => __('Skincare and makeup'),
            self::ContactLenses => __('Contact lenses and lens fluid'),
            self::Sanitary => __('Sanitary products'),
            self::VitaminsMedicine => __('Vitamins and medicine'),
            self::Nappies => __('Nappies and baby wipes'),
            self::BabyFood => __('Formula and baby food'),
            self::PetFood => __('Pet food'),
            self::CatLitter => __('Cat litter'),
            self::FleaTreatment => __('Flea and tick treatment'),
            self::Garden => __('Potting soil and bird food'),
            self::Car => __('Windscreen fluid and AdBlue'),
            self::Hobby => __('Hobby supplies'),
        };
    }

    /** What the idea covers, in English, for the Choice Jev answers. */
    public function rubric(): string
    {
        return match ($this) {
            self::Groceries => 'Everyday food from the supermarket: fresh produce, meat, fish, dairy, eggs, bread, pantry goods, snacks, frozen food',
            self::CoffeeTea => 'Coffee beans, ground coffee, coffee pods and capsules, tea',
            self::SoftDrinks => 'Soft drinks, juice, bottled water, sparkling water, energy drinks, iced tea',
            self::BeerWine => 'Beer, wine, sparkling wine, spirits',
            self::SportsNutrition => 'Protein powder, protein bars and shakes, sports supplements',
            self::Paper => 'Toilet paper, kitchen roll, tissues',
            self::Cleaning => 'Cleaning products for the house: all-purpose cleaner, toilet cleaner, glass cleaner, degreaser, sponges',
            self::Laundry => 'Laundry detergent, fabric softener, laundry pods, stain remover',
            self::Dishwasher => 'Dishwasher tablets, dishwasher salt, rinse aid, washing-up liquid',
            self::BinBags => 'Bin bags and bin liners',
            self::VacuumBags => 'Bags and filters for a vacuum cleaner',
            self::Batteries => 'Disposable or rechargeable batteries, button cells',
            self::WaterFilters => 'Water filter cartridges, descaler for a kettle or coffee machine',
            self::LightBulbs => 'Light bulbs and LED lamps',
            self::Candles => 'Candles, tea lights, air fresheners, scent sticks',
            self::PrinterInk => 'Ink cartridges and toner for a printer',
            self::DeodorantShower => 'Deodorant, shower gel, soap, hand soap',
            self::HairCare => 'Shampoo, conditioner, hair masks and styling products',
            self::OralCare => 'Toothpaste, toothbrushes and brush heads, mouthwash, floss',
            self::Shaving => 'Razor blades, razors, shaving foam and gel',
            self::Sunscreen => 'Sunscreen and after-sun',
            self::SkincareMakeup => 'Face and body skincare, make-up',
            self::ContactLenses => 'Contact lenses and lens solution',
            self::Sanitary => 'Sanitary pads, tampons, panty liners, incontinence products',
            self::VitaminsMedicine => 'Vitamins, supplements, painkillers and other over-the-counter medicine',
            self::Nappies => 'Nappies and baby wipes',
            self::BabyFood => 'Infant formula, follow-on milk and baby food',
            self::PetFood => 'Food and treats for a cat, dog or other pet',
            self::CatLitter => 'Cat litter',
            self::FleaTreatment => 'Flea and tick treatment for a pet',
            self::Garden => 'Potting soil, plant food, bird food',
            self::Car => 'Windscreen washer fluid, AdBlue, car care products',
            self::Hobby => 'Supplies used up by a hobby: craft materials, yarn, paint for art, fishing bait, model kits',
        };
    }

    /** The heading the card lists this idea under. */
    public function group(): TrackingIdeaGroup
    {
        return match ($this) {
            self::Groceries, self::CoffeeTea, self::SoftDrinks, self::BeerWine, self::SportsNutrition => TrackingIdeaGroup::FoodDrinks,
            self::Paper, self::Cleaning, self::Laundry, self::Dishwasher, self::BinBags, self::VacuumBags,
            self::Batteries, self::WaterFilters, self::LightBulbs, self::Candles, self::PrinterInk => TrackingIdeaGroup::Household,
            self::DeodorantShower, self::HairCare, self::OralCare, self::Shaving, self::Sunscreen,
            self::SkincareMakeup, self::ContactLenses, self::Sanitary => TrackingIdeaGroup::PersonalCare,
            self::VitaminsMedicine, self::Nappies, self::BabyFood => TrackingIdeaGroup::HealthBaby,
            self::PetFood, self::CatLitter, self::FleaTreatment => TrackingIdeaGroup::Pets,
            self::Garden, self::Car, self::Hobby => TrackingIdeaGroup::GardenCarHobby,
        };
    }

    /**
     * @return list<ProductCategory>
     */
    public function categories(): array
    {
        return match ($this) {
            self::Groceries => [
                ProductCategory::FreshProduce, ProductCategory::MeatFishVeg, ProductCategory::DairyEggs,
                ProductCategory::Bakery, ProductCategory::Pantry, ProductCategory::SnacksSweets, ProductCategory::Frozen,
            ],
            self::CoffeeTea => [ProductCategory::CoffeeTea],
            self::SoftDrinks => [ProductCategory::SoftDrinks],
            self::BeerWine => [ProductCategory::Alcohol],
            self::SportsNutrition => [ProductCategory::SportsNutrition],
            self::Paper => [ProductCategory::PaperDisposables],
            self::Cleaning => [ProductCategory::Cleaning],
            self::Laundry => [ProductCategory::Laundry],
            self::Candles => [ProductCategory::AirCandles],
            self::HairCare => [ProductCategory::Hair],
            self::OralCare => [ProductCategory::Oral],
            self::Shaving => [ProductCategory::Shaving],
            self::Sanitary => [ProductCategory::FeminineIncontinence],
            self::VitaminsMedicine => [ProductCategory::MedicinesSupplements],
            self::Nappies => [ProductCategory::NappiesWipes],
            self::BabyFood => [ProductCategory::BabyFood],
            self::PetFood => [ProductCategory::PetFood],
            default => [],
        };
    }

    /**
     * Words in a product title, Dutch and English, that mean this idea is
     * tracked. Anchored at word starts: "deo" must not match "video".
     */
    public function keywords(): ?string
    {
        $words = match ($this) {
            self::CoffeeTea => 'koffie|coffee|espresso|cappuccino|thee(?!licht)|(?<!ice )(?<!iced )tea\b(?! ?(?:lights?|tree))|nespresso|dolce gusto|senseo',
            self::SoftDrinks => 'frisdrank|cola\b|limonade|ice ?tea|ijsthee|mineraalwater|bruisend water|soft ?drinks?',
            self::BeerWine => 'bier\b|speciaalbier|pils\b|beer\b|wijn(?:en)?\b|wine\b(?! ?glass)|prosecco|cava\b|champagne',
            self::SportsNutrition => 'whey|eiwitshake|eiwitreep|proteïne|protein ?(?:bar|shake|powder)',
            self::Paper => 'toiletpapier|wc-papier|keukenpapier|keukenrol|toilet ?paper|kitchen ?roll|tissues|zakdoekjes',
            self::Cleaning => 'allesreiniger|schoonmaakmiddel|ontvetter|glasreiniger|wc-reiniger|all-purpose cleaner',
            self::Laundry => 'wasmiddel|wasverzachter|waspoeder|wascapsules|waspods|laundry (?:detergent|pods|liquid|capsules)|detergent|ariel pods',
            self::Dishwasher => 'vaatwastablet|vaatwasmiddel|vaatwaszout|vaatwascapsule|afwasmiddel|glansspoeler|dishwasher (?:tablets|salt|pods|detergent)|dish ?soap|washing-up liquid',
            self::BinBags => 'vuilniszak|afvalzak|pedaalemmerzak|bin ?(?:bags?|liners?)|trash ?bags?',
            self::VacuumBags => 'stofzuigerzak|stofzuigerfilter|vacuum ?(?:cleaner )?(?:bags?|filters?)',
            self::Batteries => 'batterij|knoopcel|batteries\b|duracell|energizer',
            self::WaterFilters => 'waterfilter|filterpatro|brita\b|maxtra|ontkalker|descaler',
            self::LightBulbs => 'ledlamp|led-lamp|led lamp|gloeilamp|lichtbron|light ?bulb|(?:e27|e14|gu10)\b',
            self::Candles => 'kaars(?:en)?\b|geurkaars|waxinelicht|theelicht|luchtverfrisser|geurstokjes|air ?freshener|candles?\b|tea ?lights?',
            self::PrinterInk => 'inktcartridge|inktpatro|printerinkt|inkt\b|lasertoner|toner ?cartridge|ink ?cartridge|printer ?ink',
            self::DeodorantShower => 'deo(?:dorant|spray|roller|stick)?\b|douchegel|douchecr[eè]me|doucheschuim|showergel|shower ?gel|body ?wash|handzeep|hand ?soap',
            self::HairCare => '(?<!carpet )(?<!tapijt)shampoo|(?<!air )conditioner|haarmasker|haarspray|haargel',
            self::OralCare => 'tandpasta|toothpaste|tandenborstel|opzetborstel|toothbrush|brush ?heads?|mondwater|mouthwash|flosdraad',
            self::Shaving => 'scheermes|mesjes\b|scheerschuim|scheergel|razor ?blades?|shaving|gillette',
            self::Sunscreen => 'spf|zonnebrand|zonnecr[eè]me|sunscreen|sun ?cream|aftersun|after sun',
            self::SkincareMakeup => 'dagcr[eè]me|nachtcr[eè]me|gezichtscr[eè]me|face ?(?:cream|wash)|serum\b|moisturi[sz]er|cleanser|reinigingsgel|micellair|micellar|mascara|lipstick|lippenstift|concealer',
            self::ContactLenses => 'contactlens|contactlenzen|lenzenvloeistof|contact ?lens',
            self::Sanitary => 'maandverband|tampons?\b|inlegkruisjes|incontinentie|sanitary pads?',
            self::VitaminsMedicine => 'vitamine|vitamin|multivitamine|paracetamol|ibuprofen|supplement',
            self::Nappies => 'luiers?\b|pampers|nappies|nappy\b|diapers?\b|billendoekjes|baby ?wipes',
            self::BabyFood => 'flesvoeding|opvolgmelk|zuigelingenvoeding|babyvoeding|baby ?formula',
            self::PetFood => 'kattenvoer|hondenvoer|kittenvoer|puppyvoer|brokjes|natvoer|cat ?food|dog ?food|whiskas|sheba\b|royal canin',
            self::CatLitter => 'kattenbakvulling|kattengrit|cat ?litter',
            self::FleaTreatment => 'vlooien|tekenmiddel|flea (?:treatment|collar|spray|drops)|frontline (?:combo|spot)|advantix|seresto|advocate',
            self::Garden => 'potgrond|tuinaarde|plantenvoeding|vogelvoer|strooivoer|pindakaasbollen|bird ?seed|potting ?soil',
            self::Car => 'ruitenvloeistof|ruitensproeiervloeistof|adblue|screenwash',
            default => null,
        };

        return $words === null ? null : '/\b(?:' . $words . ')/iu';
    }

    /** Whether a tracked product with this title and category ticks the idea. */
    public function isTrackedBy(string $title, ?ProductCategory $category): bool
    {
        if ($category !== null && in_array($category, $this->categories(), strict: true)) {
            return true;
        }

        $keywords = $this->keywords();

        return $keywords !== null && preg_match($keywords, $title) === 1;
    }
}

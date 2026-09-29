<?php declare(strict_types=1);

namespace App\Enums;

/**
 * A heading on the getting-started checklist, and the shops DipCatch reads
 * well for what sits under it.
 */
enum TrackingIdeaGroup: string
{
    case FoodDrinks = 'food_drinks';
    case Household = 'household';
    case PersonalCare = 'personal_care';
    case HealthBaby = 'health_baby';
    case Pets = 'pets';
    case GardenCarHobby = 'garden_car_hobby';

    public function label(): string
    {
        return match ($this) {
            self::FoodDrinks => __('Food and drinks'),
            self::Household => __('Household'),
            self::PersonalCare => __('Personal care'),
            self::HealthBaby => __('Health and baby'),
            self::Pets => __('Pets'),
            self::GardenCarHobby => __('Garden, car and hobby'),
        };
    }

    /**
     * @return list<string>
     */
    public function shops(): array
    {
        return match ($this) {
            self::FoodDrinks => ['ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'aldi.nl'],
            self::Household => ['ah.nl', 'jumbo.com', 'bol.com', 'amazon.nl'],
            self::PersonalCare => ['etos.nl', 'ah.nl', 'bol.com', 'amazon.nl'],
            self::HealthBaby => ['etos.nl', 'ah.nl', 'jumbo.com', 'bol.com'],
            self::Pets => ['zooplus.nl', 'bitiba.nl', 'brekz.nl', 'medpets.nl', 'petsplace.nl'],
            self::GardenCarHobby => ['welkoop.nl', 'bol.com', 'amazon.nl'],
        };
    }
}

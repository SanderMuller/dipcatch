<?php declare(strict_types=1);

namespace App\Enums;

/**
 * The top level of the product taxonomy. Every category belongs to exactly
 * one department. `Other` is the no-match outcome and carries one category
 * of its own.
 */
enum ProductDepartment: string
{
    case Food = 'food';
    case Baby = 'baby';
    case Health = 'health';
    case Household = 'household';
    case Pets = 'pets';
    case Electronics = 'electronics';
    case Home = 'home';
    case GardenDiy = 'garden_diy';
    case Clothing = 'clothing';
    case Toys = 'toys';
    case Sports = 'sports';
    case MediaOffice = 'media_office';
    case CarTravel = 'car_travel';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Food => __('Food & drinks'),
            self::Baby => __('Baby & child'),
            self::Health => __('Health & personal care'),
            self::Household => __('Household & cleaning'),
            self::Pets => __('Pets'),
            self::Electronics => __('Electronics'),
            self::Home => __('Home & living'),
            self::GardenDiy => __('Garden & DIY'),
            self::Clothing => __('Clothing & shoes'),
            self::Toys => __('Toys & games'),
            self::Sports => __('Sports & outdoor'),
            self::MediaOffice => __('Books, media & office'),
            self::CarTravel => __('Car & travel'),
            self::Other => __('Other'),
        };
    }

    /**
     * The English rubric a Jev Choice reads for this department. Never shown
     * to a person, so it stays untranslated and can be blunt.
     */
    public function rubric(): string
    {
        return match ($this) {
            self::Food => 'Groceries: fresh food, meat, dairy, bread, pantry goods, snacks, frozen food, drinks, coffee, alcohol',
            self::Baby => 'For a baby or toddler: nappies, wipes, formula, baby food, feeding and care gear',
            self::Health => 'Personal care and health: skin, hair, teeth, medicines, supplements, shaving, make-up, perfume, feminine care',
            self::Household => 'Keeping the house clean and running: laundry, cleaning products, paper, bin bags, storage, candles',
            self::Pets => 'For an animal: pet food, litter, pet care, pet accessories and toys',
            self::Electronics => 'Consumer electronics: phones, computers, audio, TV, cameras, wearables, cables and chargers, smart home',
            self::Home => 'Furnishing and equipping a home: furniture, kitchenware, bedding, lighting, decoration, appliances',
            self::GardenDiy => 'Garden and do-it-yourself: plants, garden tools, hand and power tools, paint, barbecue',
            self::Clothing => 'Worn on the body: clothing for women, men or children, shoes, bags, jewellery, watches',
            self::Toys => 'Play: toys, board games, puzzles, video games, consoles, building sets',
            self::Sports => 'Sport and outdoor activity: sportswear, fitness equipment, bicycles, camping gear, sports nutrition such as protein bars',
            self::MediaOffice => 'Books, music, film, office and school supplies',
            self::CarTravel => 'Car parts and car care, luggage and travel gear',
            self::Other => 'Nothing above fits, or the product cannot be told from the evidence',
        };
    }

    /**
     * @return list<ProductCategory>
     */
    public function categories(): array
    {
        return array_values(array_filter(
            ProductCategory::cases(),
            fn (ProductCategory $category): bool => $category->department() === $this,
        ));
    }

    /**
     * @return list<self>
     */
    public static function real(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $department): bool => $department !== self::Other));
    }
}

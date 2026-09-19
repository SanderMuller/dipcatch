<?php declare(strict_types=1);

namespace App\Enums;

/**
 * The leaf level of the product taxonomy: one category per product. The
 * value is `department.leaf`, so the department is readable from the stored
 * string without a lookup. `Other` is the no-match outcome.
 */
enum ProductCategory: string
{
    case FreshProduce = 'food.fresh_produce';
    case MeatFishVeg = 'food.meat_fish_veg';
    case DairyEggs = 'food.dairy_eggs';
    case Bakery = 'food.bakery';
    case Pantry = 'food.pantry';
    case SnacksSweets = 'food.snacks_sweets';
    case Frozen = 'food.frozen';
    case SoftDrinks = 'food.soft_drinks';
    case CoffeeTea = 'food.coffee_tea';
    case Alcohol = 'food.alcohol';

    case NappiesWipes = 'baby.nappies_wipes';
    case BabyFood = 'baby.baby_food';
    case BabyGear = 'baby.baby_gear';

    case SkinBody = 'health.skin_body';
    case Hair = 'health.hair';
    case Oral = 'health.oral';
    case MedicinesSupplements = 'health.medicines_supplements';
    case Shaving = 'health.shaving';
    case MakeupFragrance = 'health.makeup_fragrance';
    case FeminineIncontinence = 'health.feminine_incontinence';

    case Laundry = 'household.laundry';
    case Cleaning = 'household.cleaning';
    case PaperDisposables = 'household.paper_disposables';
    case BagsStorage = 'household.bags_storage';
    case AirCandles = 'household.air_candles';

    case PetFood = 'pets.pet_food';
    case PetCare = 'pets.pet_care';
    case PetAccessories = 'pets.pet_accessories';

    case PhonesTablets = 'electronics.phones_tablets';
    case Computers = 'electronics.computers';
    case Audio = 'electronics.audio';
    case TvVideo = 'electronics.tv_video';
    case Cameras = 'electronics.cameras';
    case Wearables = 'electronics.wearables';
    case Accessories = 'electronics.accessories';
    case SmartHome = 'electronics.smart_home';

    case Furniture = 'home.furniture';
    case Kitchen = 'home.kitchen';
    case BeddingBath = 'home.bedding_bath';
    case Lighting = 'home.lighting';
    case Decoration = 'home.decoration';
    case LargeAppliances = 'home.large_appliances';
    case SmallAppliances = 'home.small_appliances';

    case Plants = 'garden_diy.plants';
    case GardenToolsFurniture = 'garden_diy.garden_tools_furniture';
    case ToolsHardware = 'garden_diy.tools_hardware';
    case Paint = 'garden_diy.paint';
    case Barbecue = 'garden_diy.barbecue';

    case Women = 'clothing.women';
    case Men = 'clothing.men';
    case Children = 'clothing.children';
    case Shoes = 'clothing.shoes';
    case BagsAccessories = 'clothing.bags_accessories';
    case JewelleryWatches = 'clothing.jewellery_watches';

    case Toys = 'toys.toys';
    case BoardGames = 'toys.board_games';
    case VideoGames = 'toys.video_games';
    case BuildingSets = 'toys.building_sets';

    case Sportswear = 'sports.sportswear';
    case Fitness = 'sports.fitness';
    case Cycling = 'sports.cycling';
    case Camping = 'sports.camping';

    case Books = 'media_office.books';
    case MusicFilm = 'media_office.music_film';
    case Office = 'media_office.office';

    case Car = 'car_travel.car';
    case Luggage = 'car_travel.luggage';

    case Other = 'other.other';

    public function department(): ProductDepartment
    {
        return ProductDepartment::from(explode('.', $this->value, 2)[0]);
    }

    /** The part after the department, what a Jev leaf Choice returns. */
    public function leafKey(): string
    {
        return explode('.', $this->value, 2)[1];
    }

    public function label(): string
    {
        return match ($this) {
            self::FreshProduce => __('Fresh fruit & vegetables'),
            self::MeatFishVeg => __('Meat, fish & vegetarian'),
            self::DairyEggs => __('Dairy & eggs'),
            self::Bakery => __('Bread & bakery'),
            self::Pantry => __('Pantry & dry goods'),
            self::SnacksSweets => __('Snacks & sweets'),
            self::Frozen => __('Frozen'),
            self::SoftDrinks => __('Soft drinks & water'),
            self::CoffeeTea => __('Coffee & tea'),
            self::Alcohol => __('Beer, wine & spirits'),
            self::NappiesWipes => __('Nappies & wipes'),
            self::BabyFood => __('Baby food & formula'),
            self::BabyGear => __('Baby care & feeding gear'),
            self::SkinBody => __('Skin & body care'),
            self::Hair => __('Hair care'),
            self::Oral => __('Oral care'),
            self::MedicinesSupplements => __('Medicines & supplements'),
            self::Shaving => __('Shaving & hair removal'),
            self::MakeupFragrance => __('Make-up & fragrance'),
            self::FeminineIncontinence => __('Feminine & incontinence care'),
            self::Laundry => __('Laundry'),
            self::Cleaning => __('Cleaning products'),
            self::PaperDisposables => __('Paper & disposables'),
            self::BagsStorage => __('Bin bags & storage'),
            self::AirCandles => __('Air fresheners & candles'),
            self::PetFood => __('Pet food'),
            self::PetCare => __('Pet care & litter'),
            self::PetAccessories => __('Pet accessories & toys'),
            self::PhonesTablets => __('Phones & tablets'),
            self::Computers => __('Computers & laptops'),
            self::Audio => __('Audio & headphones'),
            self::TvVideo => __('TV & video'),
            self::Cameras => __('Cameras'),
            self::Wearables => __('Wearables'),
            self::Accessories => __('Cables, chargers & accessories'),
            self::SmartHome => __('Smart home'),
            self::Furniture => __('Furniture'),
            self::Kitchen => __('Kitchen & tableware'),
            self::BeddingBath => __('Bedding & bath'),
            self::Lighting => __('Lighting'),
            self::Decoration => __('Decoration'),
            self::LargeAppliances => __('Large appliances'),
            self::SmallAppliances => __('Small appliances'),
            self::Plants => __('Plants & seeds'),
            self::GardenToolsFurniture => __('Garden tools & furniture'),
            self::ToolsHardware => __('Tools & hardware'),
            self::Paint => __('Paint & decorating'),
            self::Barbecue => __('Barbecue & outdoor cooking'),
            self::Women => __("Women's clothing"),
            self::Men => __("Men's clothing"),
            self::Children => __("Children's clothing"),
            self::Shoes => __('Shoes'),
            self::BagsAccessories => __('Bags & accessories'),
            self::JewelleryWatches => __('Jewellery & watches'),
            self::Toys => __('Toys'),
            self::BoardGames => __('Board games & puzzles'),
            self::VideoGames => __('Video games & consoles'),
            self::BuildingSets => __('Building sets'),
            self::Sportswear => __('Sports clothing & shoes'),
            self::Fitness => __('Fitness equipment'),
            self::Cycling => __('Cycling'),
            self::Camping => __('Camping & outdoor'),
            self::Books => __('Books'),
            self::MusicFilm => __('Music & film'),
            self::Office => __('Office & school supplies'),
            self::Car => __('Car parts & care'),
            self::Luggage => __('Luggage'),
            self::Other => __('Other'),
        };
    }

    /**
     * The English rubric a Jev leaf Choice reads for this category. Never
     * shown to a person, so it stays untranslated and names examples.
     */
    public function rubric(): string
    {
        return match ($this) {
            self::FreshProduce => 'Fresh fruit, vegetables, herbs, salad',
            self::MeatFishVeg => 'Meat, poultry, fish, seafood, meat substitutes, tofu',
            self::DairyEggs => 'Milk, yoghurt, cheese, butter, cream, eggs, plant milks',
            self::Bakery => 'Bread, rolls, pastry, cake, crackers, breakfast cereals',
            self::Pantry => 'Pasta, rice, flour, oil, sauces, spices, tinned and jarred food, baking goods',
            self::SnacksSweets => 'Crisps, nuts, chocolate, sweets, biscuits, bars',
            self::Frozen => 'Frozen meals, frozen vegetables, ice cream, frozen pizza',
            self::SoftDrinks => 'Water, soda, juice, energy drinks, syrup, plant-based drinks sold as beverages',
            self::CoffeeTea => 'Coffee beans, ground coffee, pods and capsules, tea, hot chocolate',
            self::Alcohol => 'Beer, wine, spirits, cider, alcohol-free beer and wine',
            self::NappiesWipes => 'Nappies, diapers, baby wipes, nappy bags, potty training pants',
            self::BabyFood => 'Infant formula, follow-on milk, baby porridge, jars and pouches',
            self::BabyGear => 'Bottles, dummies, baby bath and skin care, prams, car seats, monitors',
            self::SkinBody => 'Body wash, soap, deodorant, body lotion, face cream, sunscreen',
            self::Hair => 'Shampoo, conditioner, styling, hair dye, brushes',
            self::Oral => 'Toothpaste, toothbrushes, electric toothbrush heads, floss, mouthwash',
            self::MedicinesSupplements => 'Painkillers, cold remedies, vitamins, supplements, plasters, first aid',
            self::Shaving => 'Razors, blades, shaving foam, epilators, wax',
            self::MakeupFragrance => 'Make-up, nail polish, perfume, aftershave',
            self::FeminineIncontinence => 'Tampons, pads, panty liners, incontinence products',
            self::Laundry => 'Detergent, fabric softener, stain remover, dryer sheets',
            self::Cleaning => 'All-purpose cleaner, dish soap, dishwasher tablets, bleach, sponges, cloths',
            self::PaperDisposables => 'Toilet paper, kitchen roll, tissues, napkins, disposable plates',
            self::BagsStorage => 'Bin bags, freezer bags, cling film, foil, storage boxes',
            self::AirCandles => 'Air freshener, scented candles, tea lights, matches, lighters',
            self::PetFood => 'Dog food, cat food, treats, fish food, bird seed',
            self::PetCare => 'Cat litter, flea treatment, grooming, pet shampoo',
            self::PetAccessories => 'Leads, beds, bowls, toys, aquariums, cages',
            self::PhonesTablets => 'Smartphones, tablets, e-readers, phone cases, screen protectors',
            self::Computers => 'Laptops, desktops, monitors, keyboards, mice, printers, storage drives',
            self::Audio => 'Headphones, earbuds, speakers, soundbars, turntables',
            self::TvVideo => 'Televisions, projectors, streaming boxes, TV mounts',
            self::Cameras => 'Cameras, lenses, action cameras, drones, tripods, memory cards',
            self::Wearables => 'Smartwatches, fitness trackers, watch bands',
            self::Accessories => 'Cables, chargers, power banks, adapters, batteries, hubs',
            self::SmartHome => 'Smart plugs, smart lights, thermostats, doorbells, security cameras, routers',
            self::Furniture => 'Sofas, tables, chairs, beds, wardrobes, shelves, desks',
            self::Kitchen => 'Pans, knives, plates, glasses, cutlery, storage containers, bakeware',
            self::BeddingBath => 'Duvets, pillows, sheets, towels, bath mats, shower curtains',
            self::Lighting => 'Lamps, light bulbs, LED strips, fairy lights',
            self::Decoration => 'Cushions, curtains, rugs, mirrors, frames, vases, artificial plants',
            self::LargeAppliances => 'Washing machines, dryers, dishwashers, fridges, freezers, ovens, hobs',
            self::SmallAppliances => 'Coffee machines, kettles, toasters, blenders, air fryers, vacuum cleaners, irons',
            self::Plants => 'Plants, flowers, bulbs, seeds, potting soil, fertiliser',
            self::GardenToolsFurniture => 'Garden furniture, lawnmowers, hoses, pots, sheds, garden tools',
            self::ToolsHardware => 'Drills, saws, screwdrivers, screws, nails, ladders, tool boxes',
            self::Paint => 'Paint, primer, brushes, rollers, wallpaper, filler, sealant',
            self::Barbecue => 'Barbecues, charcoal, gas, grill accessories, fire pits, pizza ovens',
            self::Women => 'Clothing for women: tops, dresses, trousers, jeans, coats, underwear, lingerie',
            self::Men => 'Clothing for men: shirts, trousers, jeans, jackets, underwear, socks',
            self::Children => 'Clothing for babies, toddlers, children and teenagers',
            self::Shoes => 'Shoes, boots, sandals, trainers worn day to day, slippers',
            self::BagsAccessories => 'Handbags, wallets, belts, scarves, hats, gloves, sunglasses',
            self::JewelleryWatches => 'Rings, necklaces, earrings, bracelets, wrist watches that are not smartwatches',
            self::Toys => 'Dolls, plush toys, cars, outdoor toys, craft kits, toys for toddlers',
            self::BoardGames => 'Board games, card games, puzzles, dice games',
            self::VideoGames => 'Video games, consoles, controllers, gaming headsets',
            self::BuildingSets => 'Building bricks and sets such as LEGO, model kits',
            self::Sportswear => 'Sports clothing, running shoes, football boots, swimwear, sports bras',
            self::Fitness => 'Dumbbells, kettlebells, yoga mats, exercise bikes, treadmills, resistance bands',
            self::Cycling => 'Bicycles, e-bikes, helmets, bike lights, locks, tyres, cycling clothing',
            self::Camping => 'Tents, sleeping bags, camping stoves, backpacks, hiking gear, coolers',
            self::Books => 'Printed books, e-books, audiobooks, comics, magazines',
            self::MusicFilm => 'CDs, vinyl, DVDs, Blu-rays, musical instruments',
            self::Office => 'Pens, paper, notebooks, folders, printer ink, school supplies',
            self::Car => 'Car parts, tyres, oil, wipers, car cleaning, dash cams, child seats for the car',
            self::Luggage => 'Suitcases, travel bags, backpacks for travel, travel accessories',
            self::Other => 'Nothing else fits',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }

    /**
     * The categories per department, in the order the department cases are
     * declared, for a grouped select.
     *
     * @return array<string, list<self>> department value => categories
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (ProductDepartment::cases() as $department) {
            $groups[$department->value] = $department->categories();
        }

        return $groups;
    }

    /**
     * The category values a filter key stands for: every category of a
     * department key, the one category of a category key, or null for
     * anything else. A department is not a category, so a `tryFrom` cannot
     * serve a filter that accepts both.
     *
     * @return list<string>|null
     */
    public static function leavesFor(?string $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $category = self::tryFrom($value);

        if ($category !== null) {
            return [$category->value];
        }

        $department = ProductDepartment::tryFrom($value);

        if ($department === null) {
            return null;
        }

        return array_map(static fn (self $category): string => $category->value, $department->categories());
    }
}

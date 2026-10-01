<?php declare(strict_types=1);

namespace App\Support\AlertSuggestion;

use App\Enums\ProductCategory;
use App\Enums\ProductDepartment;

/**
 * How deep a promotion in each category usually goes in Dutch retail, the
 * depth a deal-seeker waits for. Researched on 2026-10-01 from public
 * sources: Consumentenbond and Circana on supermarket and drugstore
 * promotions, Tweakers and the ACM on Black Friday, the Alcoholwet. Many rows
 * are estimates; tune them from DipCatch's own promotion data once it has
 * enough.
 */
final class TypicalPromotionDepth
{
    /** The deepest depth a suggestion goes to: a drugstore 2+3. */
    public const int MAX = 60;

    /** In whole percent. */
    public static function for(ProductCategory $category): int
    {
        return match ($category) {
            ProductCategory::FreshProduce, ProductCategory::MeatFishVeg => 25,
            ProductCategory::DairyEggs, ProductCategory::Bakery, ProductCategory::Frozen => 30,
            ProductCategory::Pantry, ProductCategory::SnacksSweets => 35,
            ProductCategory::SoftDrinks => 50,
            ProductCategory::CoffeeTea => 40,
            ProductCategory::Alcohol => 25,
            ProductCategory::NappiesWipes => 50,
            ProductCategory::BabyFood => 25,
            ProductCategory::BabyGear => 20,
            ProductCategory::SkinBody, ProductCategory::Hair, ProductCategory::Oral, ProductCategory::FeminineIncontinence => 50,
            ProductCategory::MedicinesSupplements => 33,
            ProductCategory::Shaving => 40,
            ProductCategory::MakeupFragrance => 30,
            ProductCategory::Laundry, ProductCategory::Cleaning, ProductCategory::PaperDisposables,
            ProductCategory::BagsStorage, ProductCategory::AirCandles => 50,
            ProductCategory::PetFood, ProductCategory::PetCare, ProductCategory::PetAccessories => 20,
            ProductCategory::PhonesTablets, ProductCategory::Computers, ProductCategory::Cameras,
            ProductCategory::Wearables, ProductCategory::Accessories => 20,
            ProductCategory::Audio => 35,
            ProductCategory::TvVideo, ProductCategory::SmartHome => 30,
            ProductCategory::Furniture, ProductCategory::Kitchen, ProductCategory::BeddingBath,
            ProductCategory::Lighting, ProductCategory::Decoration => 25,
            ProductCategory::LargeAppliances => 15,
            ProductCategory::SmallAppliances => 30,
            ProductCategory::Plants, ProductCategory::GardenToolsFurniture, ProductCategory::ToolsHardware,
            ProductCategory::Paint, ProductCategory::Barbecue => 25,
            ProductCategory::Women, ProductCategory::Men, ProductCategory::Children,
            ProductCategory::Shoes, ProductCategory::BagsAccessories => 40,
            ProductCategory::JewelleryWatches => 30,
            ProductCategory::Toys, ProductCategory::BoardGames, ProductCategory::BuildingSets => 25,
            ProductCategory::VideoGames => 30,
            ProductCategory::Sportswear, ProductCategory::Fitness, ProductCategory::Cycling,
            ProductCategory::Camping, ProductCategory::SportsNutrition => 30,
            // Dutch paper books have a fixed price, other books do not. The
            // category cannot tell them apart; Jev's `Fixed` band can.
            ProductCategory::Books, ProductCategory::MusicFilm, ProductCategory::Office => 20,
            ProductCategory::Car, ProductCategory::Luggage => 30,
            ProductCategory::Other => 20,
        };
    }

    /** The deepest discount the law allows, in whole percent. */
    public static function legalCap(?ProductCategory $category): int
    {
        // The Alcoholwet forbids a retail discount on alcohol above 25%.
        return $category === ProductCategory::Alcohol ? 25 : self::MAX;
    }

    /**
     * Whether a shop's "was" price in this department says little: a claimed
     * 50% off in electronics or clothing is mostly an inflated reference
     * price, where a supermarket's or drugstore's is a real multi-buy.
     */
    public static function distrustsClaims(ProductDepartment $department): bool
    {
        return match ($department) {
            ProductDepartment::Food, ProductDepartment::Baby, ProductDepartment::Health,
            ProductDepartment::Household, ProductDepartment::Pets, ProductDepartment::Other => false,
            ProductDepartment::Electronics, ProductDepartment::Home, ProductDepartment::GardenDiy,
            ProductDepartment::Clothing, ProductDepartment::Toys, ProductDepartment::Sports,
            ProductDepartment::MediaOffice, ProductDepartment::CarTravel => true,
        };
    }
}

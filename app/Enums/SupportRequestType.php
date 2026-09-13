<?php declare(strict_types=1);

namespace App\Enums;

enum SupportRequestType: string
{
    case Feedback = 'feedback';
    case ShopRequest = 'shop_request';
    case ShopIssue = 'shop_issue';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::Feedback => __('Share feedback'),
            self::ShopRequest => __('Request a shop'),
            self::ShopIssue => __('Report a shop problem'),
            self::Support => __('Ask for help'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Feedback => __('Tell us what works well or could be better.'),
            self::ShopRequest => __('Send a product URL from a shop you want to track.'),
            self::ShopIssue => __('Tell us what went wrong with a tracked shop.'),
            self::Support => __('Ask a question about DipCatch or your account.'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Feedback => 'chat-bubble-left-right',
            self::ShopRequest => 'shopping-bag',
            self::ShopIssue => 'exclamation-triangle',
            self::Support => 'lifebuoy',
        };
    }

    public function requiresShopUrl(): bool
    {
        return $this === self::ShopRequest || $this === self::ShopIssue;
    }
}

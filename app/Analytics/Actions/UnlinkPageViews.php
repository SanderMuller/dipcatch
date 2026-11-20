<?php declare(strict_types=1);

namespace App\Analytics\Actions;

use App\Analytics\Models\PageView;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Removes the account link from a person's past page views when they object
 * to linked usage tracking. The rows stay, counted as anonymous.
 */
final class UnlinkPageViews
{
    public function __invoke(Authenticatable $user): int
    {
        return PageView::query()
            ->where(PageView::USER_ID, $user->getAuthIdentifier())
            ->update([PageView::USER_ID => null]);
    }
}

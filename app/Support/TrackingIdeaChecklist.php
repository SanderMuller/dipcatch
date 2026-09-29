<?php declare(strict_types=1);

namespace App\Support;

use App\Enums\TrackingIdea;
use App\Enums\TrackingIdeaMarkState;
use App\Models\Product;
use App\Models\TrackingIdeaMark;
use App\Models\User;

/**
 * Where one account stands on the getting-started ideas: covered by a tracked
 * product, ticked or skipped by hand, or still open.
 */
final readonly class TrackingIdeaChecklist
{
    /**
     * @param  list<TrackingIdea>  $open
     * @param  list<TrackingIdea>  $tracked  Covered by a tracked product.
     * @param  list<TrackingIdea>  $done  Ticked by hand.
     * @param  list<TrackingIdea>  $skipped
     */
    private function __construct(
        public array $open,
        public array $tracked,
        public array $done,
        public array $skipped,
    ) {}

    public static function for(User $user): self
    {
        $products = Product::query()
            ->where('user_id', $user->getKey())
            ->get(['title', 'category', 'tracking_idea']);

        $marks = $user->trackingIdeaMarks()
            ->get()
            ->mapWithKeys(static fn (TrackingIdeaMark $mark): array => [$mark->idea->value => $mark->state]);

        $open = $tracked = $done = $skipped = [];

        foreach (TrackingIdea::cases() as $idea) {
            $mark = $marks->get($idea->value);

            // A tracked product outranks a hand mark: "not for me" beside a
            // product the person tracks would contradict itself.
            match (true) {
                $products->contains(static fn (Product $product): bool => $product->tracking_idea === $idea
                    || $idea->isTrackedBy((string) $product->title, $product->category)) => $tracked[] = $idea,
                $mark === TrackingIdeaMarkState::Skipped => $skipped[] = $idea,
                $mark === TrackingIdeaMarkState::Done => $done[] = $idea,
                default => $open[] = $idea,
            };
        }

        return new self($open, $tracked, $done, $skipped);
    }

    public function total(): int
    {
        return count(TrackingIdea::cases());
    }

    public function handled(): int
    {
        return $this->total() - count($this->open);
    }
}

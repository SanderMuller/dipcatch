{{-- The disclosure the affiliate programs and Dutch law ask for, naming the
     shops whose links earn a commission. Needs `$shops`, from
     AffiliateLink::shops(). --}}
<p class="{{ $class ?? '' }} text-xs text-zinc-500 dark:text-zinc-400" data-test="affiliate-disclosure">
    {{ __('Links to :shops are affiliate links: the shop may pay DipCatch a commission when you buy, at no cost to you. It never changes which shop comes first.', ['shops' => implode(' ' . __('and') . ' ', $shops)]) }}
    @if (in_array('bol.com', $shops, true))
        {{ __('DipCatch is not a bol.com site.') }}
    @endif
    @if (in_array('Amazon', $shops, true))
        {{ __('As an Amazon Associate, DipCatch earns from qualifying purchases.') }}
    @endif
</p>

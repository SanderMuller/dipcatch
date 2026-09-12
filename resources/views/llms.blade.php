{{-- /llms.txt — https://llmstxt.org. Plain text, English only.

     Numbers come from config so this cannot drift from the app.

     Values are echoed with {!! !!} on purpose: the response is text/plain,
     where Blade's HTML escaping would render an apostrophe in a config value
     as &#039;. Everything interpolated here is operator-set config or a
     route, never user input. --}}
# {!! config('app.name') !!}

> {!! config('app.name') !!} is a price-alert service for the Netherlands, for the things you buy anyway: groceries, pet food, coffee, skincare, vacuum-cleaner filters, water filters, anything that runs out. You paste a product link from a supermarket or webshop, add the same product from other shops, and {!! config('app.name') !!} compares them on unit price (per kilo, litre or piece) and tells you when the cheapest one drops.

Site: {!! route('home') !!}
@if (filled($contactEmail))
Contact: {!! $contactEmail !!}
@endif
Languages: English (default), Dutch ({!! route('home', ['lang' => 'nl']) !!})

## What it does

- Tracks one product across several shops: Albert Heijn, Jumbo, Dirk, Lidl, Aldi, SPAR, DekaMarkt, Poiesz, Vomar, bol.com, Amazon country sites, Zooplus, Bitiba, Dierapotheker, Pets Place, Medpets, Welkoop, Pets at Home, Etos, The Ordinary, Lookfantastic, Cult Beauty, Ulta and Walmart, plus most webshops that publish structured product data.
- Reads AH Bonus and Dirk promo prices, not only the shelf price.
- Compares pack sizes fairly by working out the price per kilo, litre or piece.
- Re-checks each shop about every {!! $recheckIntervalHours !!} hours.
- Alerts by daily email digest, in the app, or browser push.
- Gives every product an optional public page with the price per shop and a 90-day chart.

## Plans

- Free: {!! $maxProducts === null ? 'unlimited' : $maxProducts !!} products, {!! $maxShopsPerProduct === null ? 'unlimited' : $maxShopsPerProduct !!} shops per product. No card needed.
- Pro: no limits, and prices checked more often. See {!! route('pricing') !!}.

## Pages

- [Homepage]({!! route('home') !!}): what {!! config('app.name') !!} is, how it works, and the FAQ.
- [Pricing]({!! route('pricing') !!}): what Free and Pro include.
- [Privacy]({!! route('privacy') !!}): what is stored, why, and for how long.
- [Sign up]({!! route('register') !!}): create a free account.
@foreach (\App\Support\UseCases::all() as $useCase)
- [{!! $useCase->heading !!}]({!! $useCase->url() !!}): {!! $useCase->description !!}
@endforeach

## For shop operators

- The crawler identifies as DipCatchBot, reads robots.txt before every request and honours it. It fetches one product page per tracked shop about every {!! $recheckIntervalHours !!} hours. Details: {!! route('bot') !!}

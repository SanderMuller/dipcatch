{{--
    A plan button. A CTA marked `post` starts checkout, which changes state
    at Stripe, so it is a form with a CSRF token and never a plain link.
--}}
@props([
    /** @var array{href: string, post?: bool} */
    'cta',
])

@if ($cta['post'] ?? false)
    <form method="POST" action="{{ $cta['href'] }}" class="contents">
        @csrf
        <button type="submit" {{ $attributes->class('cursor-pointer') }}>{{ $slot }}</button>
    </form>
@else
    <a href="{{ $cta['href'] }}" {{ $attributes }}>{{ $slot }}</a>
@endif

{{-- Intrinsic size stated so the browser reserves the box before the file
     arrives. The mark sits in the header of every page, so without it every
     page shifts on load. The class on the usage decides the rendered size. --}}
<img src="{{ asset('images/dipcatch-logo.png') }}" alt="{{ config('app.name') }}" width="512" height="512" {{ $attributes }} />

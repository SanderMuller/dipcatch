{{--
    Inline styles on purpose. The admin panel compiles no Vite theme of its
    own, so Tailwind utilities written here would not exist in its CSS — which
    is how a 512px logo ended up covering the sidebar. The white backing keeps
    the dark-blue mark legible on the dark topbar; `currentColor` lets the
    wordmark follow the panel's own text colour in both themes.
--}}
<span style="display:inline-flex;align-items:center;gap:0.5rem;">
    <img
        src="{{ asset('images/dipcatch-logo.png') }}"
        alt=""
        width="32"
        height="32"
        style="height:2rem;width:2rem;border-radius:0.375rem;background:#fff;padding:2px;box-sizing:border-box;"
    />
    <span style="font-size:1.125rem;font-weight:700;letter-spacing:-0.01em;color:currentColor;">DipCatch</span>
</span>

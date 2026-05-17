@auth
    @if (! auth()->user()->timezone_detected_at)
        <script>
            (function () {
                try {
                    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
                    if (!tz) {
                        return;
                    }
                    const csrf = document.querySelector('meta[name="csrf-token"]');
                    if (!csrf) {
                        return;
                    }
                    // Fire-and-forget: failures are silent. The server's
                    // conditional UPDATE makes this safe to call on every
                    // page load until the cursor is stamped.
                    fetch('{{ route('profile.timezone.auto-detect') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf.getAttribute('content'),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ timezone: tz }),
                        credentials: 'same-origin',
                    }).catch(function (e) {
                        console.warn('[dipcatch] timezone auto-detect failed', e);
                    });
                } catch (e) {
                    console.warn('[dipcatch] timezone auto-detect threw', e);
                }
            })();
        </script>
    @endif
@endauth

<div class="max-w-3xl">
    <flux:heading size="xl" class="tracking-tight">{{ __("What's new") }}</flux:heading>
    <flux:text class="mt-1 max-w-[60ch] text-pretty text-base text-zinc-600 sm:text-sm dark:text-zinc-400">
        {{ __('New features, new shops, Pro features and fixes, newest first.') }}
    </flux:text>

    @if ($days === [])
        <flux:text class="mt-8 text-zinc-500 dark:text-zinc-400" data-test="changelog-empty">{{ __('Nothing here yet.') }}</flux:text>
    @else
        <div class="mt-8 divide-y divide-zinc-950/5 dark:divide-white/10">
            @foreach ($days as $day)
                <section class="py-6 first:pt-0" data-test="changelog-day">
                    <flux:heading size="lg" level="2" class="font-semibold! tracking-tight">
                        <time datetime="{{ $day['date']->toDateString() }}">{{ $day['date']->translatedFormat('j F Y') }}</time>
                    </flux:heading>

                    <ul class="mt-4 space-y-5">
                        @foreach ($day['entries'] as $entry)
                            <li data-test="changelog-entry">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <flux:badge size="sm" :color="$entry->category->color()">{{ $entry->category->label() }}</flux:badge>
                                    <flux:heading level="3" class="font-semibold!">{{ $entry->title }}</flux:heading>
                                </div>
                                @foreach ($entry->paragraphs() as $paragraph)
                                    <flux:text class="mt-1.5 max-w-[65ch] text-pretty text-base text-zinc-700 sm:text-sm dark:text-zinc-300">{{ $paragraph }}</flux:text>
                                @endforeach

                                {{-- A user starts the video, with sound: no autoplay, and
                                     preload="none" keeps the MP4s off the page load. --}}
                                @if ($entry->video !== null)
                                    <video
                                        controls
                                        preload="none"
                                        playsinline
                                        poster="{{ $entry->posterUrl() }}"
                                        width="1280"
                                        height="720"
                                        class="mt-4 aspect-video h-auto w-full max-w-2xl rounded-xl bg-zinc-950/5 ring-1 ring-zinc-950/10 dark:ring-white/10"
                                        data-test="changelog-video"
                                    >
                                        <source src="{{ $entry->videoUrl() }}" type="video/mp4">
                                        <a href="{{ $entry->videoUrl() }}">{{ __('Watch the video') }}</a>
                                    </video>
                                @elseif ($entry->image !== null)
                                    @php($imageSize = $entry->imageSize())
                                    <img
                                        src="{{ asset($entry->image['src']) }}"
                                        alt="{{ $entry->image['alt'] }}"
                                        loading="lazy"
                                        @if ($imageSize !== null) width="{{ $imageSize['width'] }}" height="{{ $imageSize['height'] }}" @endif
                                        class="mt-4 h-auto w-full max-w-2xl rounded-xl ring-1 ring-zinc-950/10 dark:ring-white/10"
                                        data-test="changelog-image"
                                    >
                                @endif

                                @if ($entry->link !== null)
                                    <flux:button size="sm" variant="ghost" icon:trailing="arrow-right" :href="$entry->linkUrl()" :wire:navigate="$entry->linkIsInApp()" class="-ms-3 mt-2" data-test="changelog-link">
                                        {{ $entry->link['label'] }}
                                    </flux:button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</div>

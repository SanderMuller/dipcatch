@props(['title', 'other'])

<span {{ $attributes }}>@foreach (\App\Support\TitleDiff::marks($title, $other) as $word)@if ($word['differs'])<mark class="rounded-sm bg-amber-200/80 px-0.5 text-inherit underline decoration-amber-600 decoration-2 underline-offset-2 dark:bg-amber-400/30 dark:decoration-amber-400" data-test="title-diff-word">{{ $word['text'] }}</mark>@else{{ $word['text'] }}@endif{{ $loop->last ? '' : ' ' }}@endforeach</span>

{{-- Rendered outside its select, so the option names the listbox variant that
     it would otherwise read from the parent. --}}
@foreach ($options as $value => $label)
    <flux:select.option variant="listbox" value="{{ $value }}">{{ $label }}</flux:select.option>
@endforeach

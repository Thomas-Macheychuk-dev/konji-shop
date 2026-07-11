@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="ortezka.pl" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-9 items-center justify-center">
            <x-app-logo-icon class="size-9" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="ortezka.pl" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-9 items-center justify-center">
            <x-app-logo-icon class="size-9" />
        </x-slot>
    </flux:brand>
@endif

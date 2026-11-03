@php
    $seller = config('legal.seller');
    $phoneHref = 'tel:'.preg_replace('/[^0-9+]/', '', (string) $seller['phone']);
@endphp
Realizujemy zlecenia na wyroby medyczne refundowane przez NFZ.
Szczegółów udzielamy w sklepie stacjonarnym w {{ $seller['city'] === 'Poznań' ? 'Poznaniu' : $seller['city'] }} i pod numerem
<a href="{{ $phoneHref }}" class="font-medium text-zinc-900 underline underline-offset-4">{{ $seller['phone'] }}</a>.

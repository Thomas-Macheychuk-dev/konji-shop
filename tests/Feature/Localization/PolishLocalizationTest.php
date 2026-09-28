<?php

use Illuminate\Support\Facades\Validator;

it('uses Polish validation messages and common field names', function (): void {
    app()->setLocale('pl');

    $validator = Validator::make([
        'email' => 'niepoprawny-adres',
        'quantity' => 0,
    ], [
        'first_name' => ['required'],
        'email' => ['required', 'email'],
        'quantity' => ['integer', 'min:1'],
        'polkurier_pickup_date' => ['required'],
    ]);

    expect($validator->errors()->first('first_name'))
        ->toBe('Pole imię jest wymagane.')
        ->and($validator->errors()->first('email'))
        ->toBe('Pole e-mail musi być prawidłowym adresem e-mail.')
        ->and($validator->errors()->first('quantity'))
        ->toBe('Pole ilość musi mieć wartość co najmniej 1.')
        ->and($validator->errors()->first('polkurier_pickup_date'))
        ->toBe('Pole data odbioru kuriera jest wymagane.');
});

it('uses Polish authentication and password reset messages', function (): void {
    app()->setLocale('pl');

    expect(__('auth.failed'))
        ->toBe('Podane dane logowania są nieprawidłowe.')
        ->and(__('auth.password'))
        ->toBe('Podane hasło jest nieprawidłowe.')
        ->and(__('passwords.sent'))
        ->toBe('Link do resetowania hasła został wysłany.')
        ->and(__('passwords.user'))
        ->toBe('Nie znaleziono użytkownika z tym adresem e-mail.');
});

it('translates Fortify two-factor validation messages', function (): void {
    app()->setLocale('pl');

    expect(__('The provided two factor authentication code was invalid.'))
        ->toBe('Podany kod uwierzytelniania dwuskładnikowego jest nieprawidłowy.')
        ->and(__('The provided two factor recovery code was invalid.'))
        ->toBe('Podany kod odzyskiwania uwierzytelniania dwuskładnikowego jest nieprawidłowy.');
});

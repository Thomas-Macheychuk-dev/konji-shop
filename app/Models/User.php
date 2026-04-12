<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\CartStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'last_name',
        'first_name',
        'house_number',
        'street',
        'apartment_number',
        'city',
        'postcode',
        'country',
        'phone_number',
        'wants_company_invoice',
        'company_name',
        'company_tax_id',
        'company_street',
        'company_house_number',
        'company_apartment_number',
        'company_city',
        'company_postcode',
        'company_country',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'wants_company_invoice' => 'boolean',
        ];
    }

    /**
     * Get the user's initials.
     */
    public function initials(): string
    {
        return Str::of(trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')))
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function activeCart(): HasOne
    {
        return $this->hasOne(Cart::class)
            ->where('status', CartStatus::Active->value);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest('placed_at')->latest('id');
    }

    public function hasPersonalAddress(): bool
    {
        return filled($this->first_name)
            && filled($this->last_name)
            && filled($this->street)
            && filled($this->house_number)
            && filled($this->city)
            && filled($this->postcode)
            && filled($this->country);
    }

    public function hasCompanyAddress(): bool
    {
        return filled($this->company_name)
            && filled($this->company_street)
            && filled($this->company_house_number)
            && filled($this->company_city)
            && filled($this->company_postcode)
            && filled($this->company_country);
    }

    public function toPersonalOrderAddressSnapshot(string $type): array
    {
        return [
            'type' => $type,
            'first_name' => $this->first_name ?? '',
            'last_name' => $this->last_name ?? '',
            'company' => null,
            'phone' => $this->phone_number ?? null,
            'email' => $this->email ?? null,
            'address_line_1' => $this->formatStreetAddress(
                $this->street,
                $this->house_number
            ),
            'address_line_2' => $this->formatApartmentAddress(
                $this->apartment_number
            ),
            'city' => $this->city ?? '',
            'postcode' => $this->postcode ?? '',
            'country_code' => $this->normalizeCountryCode($this->country),
        ];
    }

    public function toCompanyOrderAddressSnapshot(string $type): array
    {
        return [
            'type' => $type,
            'first_name' => $this->first_name ?? '',
            'last_name' => $this->last_name ?? '',
            'company' => $this->company_name ?? '',
            'phone' => $this->phone_number ?? null,
            'email' => $this->email ?? null,
            'address_line_1' => $this->formatStreetAddress(
                $this->company_street,
                $this->company_house_number
            ),
            'address_line_2' => $this->formatApartmentAddress(
                $this->company_apartment_number
            ),
            'city' => $this->company_city ?? '',
            'postcode' => $this->company_postcode ?? '',
            'country_code' => $this->normalizeCountryCode($this->company_country),
        ];
    }

    public function checkoutShippingAddressDefaults(): array
    {
        return $this->toPersonalOrderAddressSnapshot('shipping');
    }

    public function checkoutCompanyBillingAddressDefaults(): array
    {
        return $this->toCompanyOrderAddressSnapshot('billing');
    }

    protected function formatStreetAddress(?string $street, ?string $houseNumber): string
    {
        return trim(implode(' ', array_filter([
            $street,
            $houseNumber,
        ])));
    }

    protected function formatApartmentAddress(?string $apartmentNumber): ?string
    {
        $apartmentNumber = trim((string) $apartmentNumber);

        return $apartmentNumber !== '' ? $apartmentNumber : null;
    }

    protected function normalizeCountryCode(?string $countryCode): string
    {
        $countryCode = strtoupper(trim((string) $countryCode));

        return $countryCode !== '' ? $countryCode : 'PL';
    }

    public function prefersCompanyInvoice(): bool
    {
        return $this->wants_company_invoice && $this->hasCompanyAddress();
    }
}

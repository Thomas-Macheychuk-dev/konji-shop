<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Enums\DeliveryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class CheckoutShippingQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'delivery_provider' => $this->input('delivery_provider', DeliveryProvider::POLKURIER->value),
            'delivery_carrier' => $this->input('delivery_carrier', DeliveryCarrier::INPOST->value),
            'delivery_service' => $this->input('delivery_service', DeliveryService::PARCEL_LOCKER->value),
            'shipping_country_code' => strtoupper((string) $this->input('shipping_country_code', 'PL')),
        ]);
    }

    public function rules(): array
    {
        return [
            'delivery_provider' => ['required', 'string', Rule::in(DeliveryProvider::options())],
            'delivery_carrier' => ['required', 'string', Rule::in(DeliveryCarrier::options())],
            'delivery_service' => ['required', 'string', Rule::in(DeliveryService::options())],
            'delivery_locker_code' => ['nullable', 'string', 'max:20'],

            'shipping_postcode' => ['nullable', 'string', 'max:30'],
            'shipping_country_code' => ['nullable', 'string', 'size:2', Rule::in(array_keys(config('countries', [])))],

            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $provider = (string) $this->input('delivery_provider');
            $carrier = (string) $this->input('delivery_carrier');
            $service = (string) $this->input('delivery_service');

            if ($provider !== DeliveryProvider::POLKURIER->value) {
                $validator->errors()->add('delivery_provider', __('Unsupported delivery provider.'));

                return;
            }

            if ($service === DeliveryService::PARCEL_LOCKER->value) {
                if ($carrier !== DeliveryCarrier::INPOST->value) {
                    $validator->errors()->add(
                        'delivery_carrier',
                        __('Parcel locker delivery is only available through InPost.')
                    );
                }

                return;
            }

            if ($service === DeliveryService::COURIER->value) {
                if ($carrier === DeliveryCarrier::LOCAL_PICKUP->value) {
                    $validator->errors()->add(
                        'delivery_carrier',
                        __('Local pickup cannot be used as a courier carrier.')
                    );
                }

                if (! $this->filled('shipping_postcode')) {
                    $validator->errors()->add(
                        'shipping_postcode',
                        __('Enter postcode to calculate delivery price.')
                    );
                }

                return;
            }

            if ($service === DeliveryService::LOCAL_PICKUP->value) {
                if ($carrier !== DeliveryCarrier::LOCAL_PICKUP->value) {
                    $validator->errors()->add(
                        'delivery_carrier',
                        __('Local pickup must use the local pickup carrier.')
                    );
                }

                if ($this->filled('delivery_locker_code')) {
                    $validator->errors()->add(
                        'delivery_locker_code',
                        __('Parcel locker code is not used for local pickup.')
                    );
                }
            }
        });
    }

    public function shippingAddressData(): array
    {
        return [
            'postcode' => (string) $this->input('shipping_postcode', ''),
            'country_code' => (string) $this->input('shipping_country_code', 'PL'),
        ];
    }
}

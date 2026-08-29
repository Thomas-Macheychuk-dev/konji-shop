<?php

declare(strict_types=1);

namespace App\Services\Neoxmed;

final class NeoxmedCategoryResolver
{
    private const STABILIZERS = 'stabilizatory-ortopedyczne';

    private const SHOULDER = 'produkty-ortopedyczne-bark-stabilizatory-ortopedyczne';

    private const KNEE = 'produkty-ortopedyczne-stabilizatory-ortopedyczne-staw-kolanowy';

    private const ANKLE = 'produkty-ortopedyczne-stabilizatory-ortopedyczne-staw-skokowy';

    private const WALKERS = 'walkery';

    private const ELBOW = 'produkty-ortopedyczne-stabilizatory-ortopedyczne-lokiec';

    private const WRIST = 'produkty-ortopedyczne-stabilizatory-ortopedyczne-nadgarstek';

    private const THUMB = 'kciuk';

    private const SPINE = 'produkty-ortopedyczne-stabilizatory-ortopedyczne-kregoslup';

    private const ABDOMEN = 'brzuch';

    private const CERVICAL_SPINE = 'kregoslup-szyjny';

    private const SLINGS = 'produkty-ortopedyczne-stabilizatory-ortopedyczne-temblaki';

    /**
     * @param  array<string, mixed>  $source
     * @return list<array{target_slug:string,target_path:list<string>,reason:string}>
     */
    public function resolve(array $source): array
    {
        $code = $this->normalizedCode($source['source_code'] ?? null);
        $externalId = $this->normalizedCode($source['external_product_id'] ?? null);

        if ($code === null) {
            return [];
        }

        if (str_starts_with($code, 'B-')) {
            return [$this->target(self::SHOULDER, 'NeoxMed B-series is shoulder orthopaedics.')];
        }

        if (str_starts_with($code, 'K-') || str_starts_with($code, 'EK-')) {
            return [$this->target(self::KNEE, 'NeoxMed K/EK-series is knee support and orthosis.')];
        }

        if (str_starts_with($code, 'SZ-')) {
            return [$this->target(self::CERVICAL_SPINE, 'NeoxMed SZ-series is cervical orthopaedics.')];
        }

        if (in_array($code, ['S-05', 'S-06'], true)) {
            return [$this->target(self::WALKERS, 'NeoxMed S-05/S-06 are Air Walker orthoses.')];
        }

        if (str_starts_with($code, 'S-') || str_starts_with($code, 'ES-')) {
            return [$this->target(self::ANKLE, 'NeoxMed S/ES-series is ankle support and orthosis.')];
        }

        if (str_starts_with($code, 'L-') || str_starts_with($code, 'EL-')) {
            return [$this->target(self::ELBOW, 'NeoxMed L/EL-series is elbow support and orthosis.')];
        }

        if (str_starts_with($code, 'N-') || str_starts_with($code, 'EN-')) {
            return $this->upperLimbTargets($code);
        }

        if (str_starts_with($code, 'P-')) {
            return [$this->target(
                in_array($code, ['P-05', 'P-06', 'P-13', 'P-30'], true) ? self::ABDOMEN : self::SPINE,
                in_array($code, ['P-05', 'P-06', 'P-13', 'P-30'], true)
                    ? 'NeoxMed product is an abdominal/postpartum belt.'
                    : 'NeoxMed P-series product is a lumbar/spinal support.',
            )];
        }

        if (str_starts_with($code, 'T-')) {
            $targets = [$this->target(self::SLINGS, 'NeoxMed T-series is a sling product.')];

            if ($code === 'T-03' || $externalId === 'T-03') {
                $targets[] = $this->target(self::SHOULDER, 'T-03 Neurotemblak is also classified by NeoxMed under shoulder orthoses.');
            }

            return $targets;
        }

        if (in_array($code, ['C-01', 'U-01'], true)) {
            return [$this->target(self::STABILIZERS, 'NeoxMed calf/thigh stabilizer has no more specific generic Konji leaf category.')];
        }

        return [];
    }

    /** @return list<array{target_slug:string,target_path:list<string>,reason:string}> */
    private function upperLimbTargets(string $code): array
    {
        if ($code === 'N-09') {
            return [$this->target(self::THUMB, 'N-09 is a thumb stabilizer.')];
        }

        if (in_array($code, ['N-02', 'N-04', 'N-07'], true)) {
            return [
                $this->target(self::WRIST, 'Product stabilizes the wrist.'),
                $this->target(self::THUMB, 'Product also stabilizes the thumb.'),
            ];
        }

        if ($code === 'N-10') {
            return [$this->target(self::STABILIZERS, 'N-10 hand/finger orthosis has no dedicated generic finger leaf in the Produkty ortopedyczne subtree.')];
        }

        return [$this->target(self::WRIST, 'NeoxMed N/EN-series is wrist/hand orthopaedics.')];
    }

    /** @return array{target_slug:string,target_path:list<string>,reason:string} */
    private function target(string $slug, string $reason): array
    {
        return [
            'target_slug' => $slug,
            'target_path' => $this->path($slug),
            'reason' => $reason,
        ];
    }

    /** @return list<string> */
    private function path(string $slug): array
    {
        return match ($slug) {
            self::STABILIZERS => ['Produkty ortopedyczne', 'Stabilizatory ortopedyczne'],
            self::SHOULDER => ['Produkty ortopedyczne', 'Bark', 'Stabilizatory ortopedyczne'],
            self::KNEE => ['Produkty ortopedyczne', 'Stabilizatory ortopedyczne', 'Staw kolanowy'],
            self::ANKLE => ['Produkty ortopedyczne', 'Stabilizatory ortopedyczne', 'Staw skokowy'],
            self::WALKERS => ['Produkty ortopedyczne', 'Walkery'],
            self::ELBOW => ['Produkty ortopedyczne', 'Stabilizatory ortopedyczne', 'Łokieć'],
            self::WRIST => ['Produkty ortopedyczne', 'Stabilizatory ortopedyczne', 'Nadgarstek'],
            self::THUMB => ['Produkty ortopedyczne', 'Stabilizatory ortopedyczne', 'Kciuk'],
            self::SPINE => ['Produkty ortopedyczne', 'Stabilizatory ortopedyczne', 'Kręgosłup'],
            self::ABDOMEN => ['Produkty ortopedyczne', 'Stabilizatory ortopedyczne', 'Brzuch'],
            self::CERVICAL_SPINE => ['Produkty ortopedyczne', 'Stabilizatory ortopedyczne', 'Kręgosłup szyjny'],
            self::SLINGS => ['Produkty ortopedyczne', 'Stabilizatory ortopedyczne', 'Temblaki'],
            default => [],
        };
    }

    private function normalizedCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));

        return $value === '' ? null : $value;
    }
}

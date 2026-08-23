<?php

declare(strict_types=1);

use App\Services\Security\IpRangeMatcher;

it('matches IPv4 and IPv6 CIDR ranges without widening them', function (): void {
    $matcher = app(IpRangeMatcher::class);

    expect($matcher->contains('66.249.66.1', '66.249.64.0/19'))->toBeTrue()
        ->and($matcher->contains('66.250.1.1', '66.249.64.0/19'))->toBeFalse()
        ->and($matcher->contains('2001:4860:4801:10::1', '2001:4860:4801::/48'))->toBeTrue()
        ->and($matcher->contains('2001:4860:4802::1', '2001:4860:4801::/48'))->toBeFalse()
        ->and($matcher->contains('not-an-ip', '66.249.64.0/19'))->toBeFalse();
});

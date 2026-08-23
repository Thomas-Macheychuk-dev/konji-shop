<?php

declare(strict_types=1);

it('rate limits storefront and mutation traffic while exempting trusted machine endpoints', function (): void {
    $config = file_get_contents(base_path('docker/nginx/production.conf'));

    expect($config)
        ->toContain('zone=konji_storefront')
        ->toContain('zone=konji_mutations')
        ->toContain('limit_req_status 429;')
        ->toContain('location = /payments/paynow/notifications')
        ->toContain('location = /up')
        ->toContain('fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr;');
});

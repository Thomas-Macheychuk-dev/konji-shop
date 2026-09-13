<?php

declare(strict_types=1);

it('restores original visitor ips only from Cloudflare proxy networks', function (): void {
    $config = (string) file_get_contents(base_path('docker/nginx/cloudflare-real-ip.conf'));
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    expect($config)
        ->toContain('set_real_ip_from 173.245.48.0/20;')
        ->toContain('set_real_ip_from 188.114.96.0/20;')
        ->toContain('set_real_ip_from 2606:4700::/32;')
        ->toContain('real_ip_header CF-Connecting-IP;')
        ->toContain('real_ip_recursive on;')
        ->not->toContain('set_real_ip_from 0.0.0.0/0;')
        ->not->toContain('set_real_ip_from ::/0;');

    expect($dockerfile)
        ->toContain('COPY docker/nginx/cloudflare-real-ip.conf /etc/nginx/conf.d/01-cloudflare-real-ip.conf');
});

it('keeps production canonical redirects single-hop for approved legacy urls', function (): void {
    $config = (string) file_get_contents(base_path('docker/nginx/production.conf'));
    $snippet = (string) file_get_contents(base_path('docker/nginx/legacy-seo/available/10-product-redirects-enabled.conf'));

    expect($config)
        ->toContain('map $host $legacy_seo_redirect_origin {')
        ->toContain('staging.ortezka.pl  https://staging.ortezka.pl;')
        ->toContain('ortezka.pl          https://ortezka.pl;')
        ->toContain('www.ortezka.pl      https://ortezka.pl;');

    expect($snippet)
        ->toContain('return 301 $legacy_seo_redirect_origin$legacy_seo_product_redirect_target;')
        ->not->toContain('https://$host$legacy_seo_product_redirect_target');
});

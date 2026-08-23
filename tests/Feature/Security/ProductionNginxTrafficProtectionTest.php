<?php

declare(strict_types=1);

it('serves the staging storefront over HTTPS while keeping ACME renewal available', function (): void {
    $config = file_get_contents(base_path('docker/nginx/production.conf'));
    $compose = file_get_contents(base_path('docker-compose.prod.yml'));
    $dockerfile = file_get_contents(base_path('Dockerfile'));

    expect($config)
        ->toContain('listen 80;')
        ->toContain('server_name staging.ortezka.pl;')
        ->toContain('location ^~ /.well-known/acme-challenge/')
        ->toContain('root /var/www/certbot;')
        ->toContain('return 301 https://staging.ortezka.pl$request_uri;')
        ->toContain('listen 443 ssl;')
        ->toContain('ssl_certificate /etc/letsencrypt/live/staging.ortezka.pl/fullchain.pem;')
        ->toContain('ssl_certificate_key /etc/letsencrypt/live/staging.ortezka.pl/privkey.pem;')
        ->toContain('ssl_protocols TLSv1.2 TLSv1.3;')
        ->toContain('fastcgi_param HTTPS on;')
        ->toContain('fastcgi_param HTTP_X_FORWARDED_PROTO https;');

    expect($compose)
        ->toContain('${HTTP_PORT:-80}:80')
        ->toContain('${HTTPS_PORT:-443}:443')
        ->toContain('/etc/letsencrypt:/etc/letsencrypt:ro')
        ->toContain('/var/www/certbot:/var/www/certbot:ro');

    expect($dockerfile)->toContain('EXPOSE 80 443');
});

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

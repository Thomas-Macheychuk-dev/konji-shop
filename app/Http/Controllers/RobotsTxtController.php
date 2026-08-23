<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

final class RobotsTxtController extends Controller
{
    public function __invoke(): Response
    {
        $lines = [
            'User-agent: Googlebot',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /account',
            'Disallow: /cart',
            'Disallow: /checkout',
            'Disallow: /guest',
            'Disallow: /human-check',
            'Disallow: /payments',
            'Disallow: /settings',
            '',
            'User-agent: Googlebot-Image',
            'Allow: /',
            '',
            'User-agent: Googlebot-Video',
            'Allow: /',
            '',
            'User-agent: Storebot-Google',
            'Allow: /',
            '',
            'User-agent: GoogleOther',
            'Allow: /',
            '',
            'User-agent: *',
            'Disallow: /',
            '',
            'Sitemap: '.route('sitemap'),
        ];

        return response(implode(PHP_EOL, $lines).PHP_EOL)
            ->header('Content-Type', 'text/plain');
    }
}

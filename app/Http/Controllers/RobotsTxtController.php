<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

final class RobotsTxtController extends Controller
{
    public function __invoke(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /checkout',
            'Disallow: /cart',
            'Disallow: /account',
            '',
            'Sitemap: '.route('sitemap'),
        ];

        return response(implode(PHP_EOL, $lines).PHP_EOL)
            ->header('Content-Type', 'text/plain');
    }
}

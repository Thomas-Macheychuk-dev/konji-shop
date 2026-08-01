<?php

declare(strict_types=1);

namespace App\Services\Vermeiren;

use Illuminate\Support\Str;

final class VermeirenColorNameNormalizer
{
    public function normalize(string $name, string $type): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $prefixPattern = match ($type) {
            'upholstery' => '/^(?:upholstery(?:\s+colou?r)?|tapicerka)\s*[-:–—]?\s*/iu',
            'frame' => '/^(?:frame(?:\s+colou?r)?|rama)\s*[-:–—]?\s*/iu',
            default => null,
        };

        if ($prefixPattern !== null) {
            $name = trim(preg_replace($prefixPattern, '', $name) ?? $name);
        }

        $translations = [
            '/\bdark[ -]gr(?:e|a)y\b/iu' => 'ciemnoszary',
            '/\blight[ -]gr(?:e|a)y\b/iu' => 'jasnoszary',
            '/\bdark[ -]blue\b/iu' => 'ciemnoniebieski',
            '/\blight[ -]blue\b/iu' => 'jasnoniebieski',
            '/\bnavy(?:[ -]blue)?\b/iu' => 'granatowy',
            '/\banthracite\b/iu' => 'antracytowy',
            '/\bburgundy\b/iu' => 'bordowy',
            '/\bturquoise\b/iu' => 'turkusowy',
            '/\bsilver\b/iu' => 'srebrny',
            '/\bgold\b/iu' => 'złoty',
            '/\bcream\b/iu' => 'kremowy',
            '/\blime\b/iu' => 'limonkowy',
            '/\bwhite\b/iu' => 'biały',
            '/\bblack\b/iu' => 'czarny',
            '/\bred\b/iu' => 'czerwony',
            '/\bgreen\b/iu' => 'zielony',
            '/\bblue\b/iu' => 'niebieski',
            '/\bgr(?:e|a)y\b/iu' => 'szary',
            '/\byellow\b/iu' => 'żółty',
            '/\borange\b/iu' => 'pomarańczowy',
            '/\bbrown\b/iu' => 'brązowy',
            '/\bbeige\b/iu' => 'beżowy',
            '/\bpurple\b/iu' => 'fioletowy',
            '/\bpink\b/iu' => 'różowy',
        ];

        $name = preg_replace(array_keys($translations), array_values($translations), $name) ?? $name;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        if (
            preg_match(
                '/^(czarny|szary|biały|czerwony|zielony|niebieski|granatowy)\s+(nylon|dartex)$/iu',
                $name,
                $matches,
            ) === 1
        ) {
            $name = Str::ucfirst(Str::lower($matches[2])).' '.Str::lower($matches[1]);
        }

        return Str::ucfirst($name);
    }
}

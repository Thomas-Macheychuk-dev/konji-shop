<?php

declare(strict_types=1);

namespace App\Services\Peruka;

final class PerukaProductHtmlCleaner
{
    public static function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = trim($html);

        if ($html === '') {
            return null;
        }

        $html = str_replace(['<!--[mode:html]-->', '<!--[mode:tiny]-->'], '', $html);
        $html = preg_replace('/<a\b[^>]*>/iu', '', $html) ?: $html;
        $html = preg_replace('/<\/a>/iu', '', $html) ?: $html;
        $html = preg_replace("/\\sdata-(?:start|end)=(['\"'])[^'\"']*\\1/iu", '', $html) ?: $html;
        $html = preg_replace("/\\scontenteditable=(['\"'])[^'\"']*\\1/iu", '', $html) ?: $html;
        $html = preg_replace("/\\sclass=(['\"'])Mso[^'\"']*\\1/iu", '', $html) ?: $html;
        $html = preg_replace('/<\/?span\b[^>]*>/iu', '', $html) ?: $html;
        $html = preg_replace('/\s+/u', ' ', $html) ?: $html;
        $html = preg_replace('/>\s+</u', '><', $html) ?: $html;
        $html = trim($html);

        return $html !== '' ? $html : null;
    }
}

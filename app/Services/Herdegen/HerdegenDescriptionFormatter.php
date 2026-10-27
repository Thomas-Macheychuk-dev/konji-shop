<?php

declare(strict_types=1);

namespace App\Services\Herdegen;

use App\Services\Qmed\QmedDescriptionSanitizer;

final class HerdegenDescriptionFormatter
{
    public function __construct(
        private readonly QmedDescriptionSanitizer $sanitizer,
    ) {}

    public function format(string $html): string
    {
        // Sanitize supplier HTML before any output.
        $html = $this->sanitizer->sanitize($html);

        // Remove leading line breaks after opening formatting elements.
        $html = preg_replace(
            '~((?:<(?:p|div|strong|b|em|i)\b[^>]*>\s*)+)(?:<br\s*/?>\s*)+~i',
            '$1',
            $html,
        ) ?? $html;

        // A maximum of one explicit break between pieces of content.
        // Block elements already provide their own paragraph spacing.
        $html = preg_replace(
            '~(?:<br\s*/?>\s*){2,}~i',
            '<br>',
            $html,
        ) ?? $html;

        // Remove breaks directly before closing block elements.
        $html = preg_replace(
            '~(?:<br\s*/?>\s*)+(</(?:strong|b|em|i)>\s*)*(</(?:p|div|h[1-6]|li)>)~i',
            '$1$2',
            $html,
        ) ?? $html;

        // Remove paragraphs containing only whitespace/breaks.
        $html = preg_replace(
            '~<p>\s*(?:<br\s*/?>\s*)*</p>~i',
            '',
            $html,
        ) ?? $html;

        // Supplier sometimes wraps whole paragraphs in bold tags.
        // Preserve inline emphasis, but normalize fully bold paragraphs.
        $html = preg_replace_callback(
            '~<p>\s*<(b|strong)>(.*?)</\1>\s*</p>~is',
            static function (array $matches): string {
                $content = trim($matches[2]);

                // Preserve short headings and labels.
                $plain = trim(strip_tags($content));

                if (mb_strlen($plain) <= 100) {
                    return $matches[0];
                }

                return '<p>'.$content.'</p>';
            },
            $html,
        ) ?? $html;

        return trim($html);
    }
}

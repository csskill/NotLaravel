<?php

namespace Nraa\Http;

final class HtmlOutputMinifier
{
    private function __construct() {}

    public static function minify(string $html): string
    {
        if ($html === '' || stripos($html, '<html') === false) {
            return $html;
        }

        $preserved = [];
        $index = 0;

        $minified = preg_replace_callback(
            '/<(script|style|pre|textarea|svg)\b[^>]*>.*?<\/\1>/is',
            static function (array $match) use (&$preserved, &$index): string {
                $token = "__HTML_MIN_PRESERVE_{$index}__";
                $preserved[$token] = $match[0];
                $index++;
                return $token;
            },
            $html
        );

        if (!is_string($minified)) {
            return $html;
        }

        // Remove standard HTML comments but keep IE conditionals / special declarations.
        $minified = preg_replace('/<!--(?!\[if\b)(?!<!)(?!>).*?-->/is', '', $minified) ?? $minified;

        // Collapse runs of whitespace outside preserved blocks.
        $minified = preg_replace('/[\r\n\t ]+/u', ' ', $minified) ?? $minified;
        $minified = trim($minified);

        if ($preserved !== []) {
            $minified = strtr($minified, $preserved);
        }

        return $minified;
    }
}

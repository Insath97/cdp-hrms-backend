<?php

namespace App\Support;

class TextNormalizer
{
    /**
     * Convert exotic Unicode letters/digits (fancy fonts, fullwidth, circled,
     * accented) into plain ASCII equivalents, trim and collapse whitespace.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $text = $value;

        // 1. Mathematical Alphanumeric Symbols (U+1D400 - U+1D7FF)
        $text = preg_replace_callback('/[\x{1D400}-\x{1D7FF}]/u', function ($m) {
            $cp = mb_ord($m[0], 'UTF-8');

            // Digits run (U+1D7CE - U+1D7FF)
            if ($cp >= 0x1D7CE) {
                return (string) (($cp - 0x1D7CE) % 10);
            }

            // Letters: each style block is 52 chars (A-Z then a-z)
            $i = ($cp - 0x1D400) % 52;
            return $i < 26 ? chr(0x41 + $i) : chr(0x61 + $i - 26);
        }, $text);

        // 2. Fullwidth forms (U+FF01 - U+FF5E)
        $text = preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', function ($m) {
            return chr(0x21 + (mb_ord($m[0], 'UTF-8') - 0xFF01));
        }, $text);

        // 3. Circled uppercase (U+24B6 - U+24CF) and lowercase (U+24D0 - U+24E9)
        $text = preg_replace_callback('/[\x{24B6}-\x{24CF}]/u', function ($m) {
            return chr(0x41 + (mb_ord($m[0], 'UTF-8') - 0x24B6));
        }, $text);
        $text = preg_replace_callback('/[\x{24D0}-\x{24E9}]/u', function ($m) {
            return chr(0x61 + (mb_ord($m[0], 'UTF-8') - 0x24D0));
        }, $text);

        // 4. Accented Latin -> base ASCII
        $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($translit !== false) {
            $text = $translit;
        }

        // 5. Collapse whitespace + trim
        $text = preg_replace('/\s+/u', ' ', (string) $text);

        return trim($text);
    }
}
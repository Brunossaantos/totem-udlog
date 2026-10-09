<?php

namespace Util;

final class TextoEtiqueta
{
    private static ?array $mapa = null;

    public static function paraAscii(string $texto, int $max = 0): string
    {
        if ($texto === '') {
            return '';
        }
        if (preg_match('//u', $texto) !== 1) {
            $texto = self::latin1ParaUtf8($texto);
        }

        $texto = strtr($texto, self::mapa());

        if (class_exists('Normalizer', false) && preg_match('/[^\x00-\x7F]/', $texto) === 1) {
            $nfd = \Normalizer::normalize($texto, \Normalizer::FORM_D);
            if (is_string($nfd)) {
                $texto = preg_replace('/\p{Mn}+/u', '', $nfd) ?? $texto;
            }
        }

        $texto = preg_replace('/[\p{Z}\p{Cc}]+/u', ' ', $texto) ?? '';
        $texto = preg_replace('/[^\x20-\x7E]+/', '', $texto) ?? '';
        $texto = trim(preg_replace('/ {2,}/', ' ', $texto) ?? '');

        if ($max > 0 && strlen($texto) > $max) {
            $texto = rtrim(substr($texto, 0, $max));
        }
        return $texto;
    }

    private static function latin1ParaUtf8(string $s): string
    {
        $out = '';
        $n = strlen($s);
        for ($i = 0; $i < $n; $i++) {
            $b = ord($s[$i]);
            $out .= $b < 0x80 ? $s[$i] : chr(0xC0 | ($b >> 6)) . chr(0x80 | ($b & 0x3F));
        }
        return $out;
    }

    private static function mapa(): array
    {
        if (self::$mapa !== null) {
            return self::$mapa;
        }
        $grupos = [
            'A' => "ÀÁÂÃÄÅĀĂĄǍ", 'a' => "àáâãäåāăąǎ",
            'C' => "ÇĆĈĊČ", 'c' => "çćĉċč",
            'D' => "ĎĐÐ", 'd' => "ďđð",
            'E' => "ÈÉÊËĒĔĖĘĚ", 'e' => "èéêëēĕėęě",
            'G' => "ĜĞĠĢ", 'g' => "ĝğġģ",
            'H' => "ĤĦ", 'h' => "ĥħ",
            'I' => "ÌÍÎÏĨĪĬĮİǏ", 'i' => "ìíîïĩīĭįıǐ",
            'J' => "Ĵ", 'j' => "ĵ",
            'K' => "Ķ", 'k' => "ķĸ",
            'L' => "ĹĻĽĿŁ", 'l' => "ĺļľŀł",
            'N' => "ÑŃŅŇŊ", 'n' => "ñńņňŉŋ",
            'O' => "ÒÓÔÕÖØŌŎŐǑ", 'o' => "òóôõöøōŏőǒ",
            'R' => "ŔŖŘ", 'r' => "ŕŗř",
            'S' => "ŚŜŞŠ", 's' => "śŝşš",
            'T' => "ŢŤŦ", 't' => "ţťŧ",
            'U' => "ÙÚÛÜŨŪŬŮŰŲǓ", 'u' => "ùúûüũūŭůűųǔ",
            'W' => "Ŵ", 'w' => "ŵ",
            'Y' => "ÝŶŸ", 'y' => "ýÿŷ",
            'Z' => "ŹŻŽ", 'z' => "źżž",
        ];
        $mapa = [];
        foreach ($grupos as $ascii => $chars) {
            foreach (preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
                $mapa[$c] = (string) $ascii;
            }
        }
        $mapa += [
            "Æ" => 'AE', "æ" => 'ae', "Œ" => 'OE', "œ" => 'oe',
            "ß" => 'ss', "ẞ" => 'SS', "Þ" => 'TH', "þ" => 'th',
            "ª" => 'a', "º" => 'o', "Ĳ" => 'IJ', "ĳ" => 'ij', "ſ" => 's',
            "\u{00A0}" => ' ',
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-',
        ];
        return self::$mapa = $mapa;
    }
}

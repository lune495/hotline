<?php

namespace App\Support;

class MailHeader
{
    public static function decode(string $value): string
    {
        if (!preg_match('/=\?[^?]+\?[bq]\?/i', $value)) {
            return $value;
        }

        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false ? $value : $decoded;
    }
}

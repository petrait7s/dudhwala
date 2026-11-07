<?php

namespace App\Services;

/** Bangladesh mobile numbers, stored as 01XXXXXXXXX. */
final class Phone
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) return null;
        $d = preg_replace('/[\s\-().]/', '', strtr($raw, ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']));
        if (str_starts_with($d, '+880')) $d = '0'.substr($d, 4);
        elseif (str_starts_with($d, '880')) $d = '0'.substr($d, 3);
        elseif (preg_match('/^1[3-9]\d{8}$/', $d)) $d = '0'.$d;
        return preg_match('/^01[3-9]\d{8}$/', $d) ? $d : null;
    }
}

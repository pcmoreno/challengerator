<?php
declare(strict_types=1);

namespace App\Helpers;

class StringToHandle
{
    public static function stringToHandle($string): string
    {
        $updatedString = strtolower($string);
        $updatedString = str_replace(' ', '_', $updatedString);
        $updatedString = preg_replace("/[^A-Za-z0-9\-_]/", '', $updatedString);
        return $updatedString;
    }
}

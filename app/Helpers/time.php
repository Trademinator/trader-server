<?php

namespace Trademinator\Time;

if (! function_exists('yesterday_unixtime')) {
    function yesterday_unixtime($days = 1)
    {
        return time() - (24 * 60 * 60 * $days);
    }
}

if (! function_exists('last_month_unixtime')) {
    function last_month_unixtime($months = 1)
    {
        return time() - (24 * 60 * 60 * 30 * $months);
    }
}

if (! function_exists('go_back')) {
    function go_back(int $increment_in_seconds, int $steps_back)
    {
        return time() - ($steps_back * $increment_in_seconds);
    }
}

if (! function_exists('to_unixtime')) {
    function to_unixtime(string $dateTime): int|bool
    {
        $timestamp = strtotime($dateTime);

        return $timestamp;
    }
}

if (! function_exists('periods_to_seconds')) {
    function periods_to_seconds(string $s = '1m'): int
    {
        $n = 0;
        $multiplier = 0;
        if (preg_match('/(?P<n>\d+)(?P<p>\w)/', $s, $matches)) {
            $period = $matches['p'];
            $n = intval($matches['n']);
            switch ($period) {
                case 'm':
                    $multiplier = 60;
                    break;
                case 'h':
                    $multiplier = 3600;
                    break;
                case 'd':
                    $multiplier = 86400;
                    break;
                case 'w':
                    $multiplier = 604800;
                    break;
                case 'M':                   // A month is 4 weeks
                    $multiplier = 2419200;
                    break;
                case 'y':
                case 'Y':                   // A year is 365 days
                    $multiplier = 31536000;
                    break;
                default:
                    $multiplier = 60;
            }
        }

        return $n * $multiplier;
    }
}

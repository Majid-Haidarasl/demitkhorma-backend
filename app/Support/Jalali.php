<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

class Jalali
{
    /**
     * @return array{0:int,1:int,2:int} [year, month, day]
     */
    public static function ymd(DateTimeInterface|string|null $date): array
    {
        if ($date === null || $date === '') {
            return [0, 0, 0];
        }

        $c = Carbon::parse($date);

        return self::fromGregorian((int) $c->year, (int) $c->month, (int) $c->day);
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }
}

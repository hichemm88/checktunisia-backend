<?php

namespace App\Support;

class Money
{
    /**
     * Tunisian dinar amounts are quoted in millimes (3 decimals) using a
     * comma as the decimal separator (e.g. "119,000 TND") — keep this
     * consistent with the frontend's formatTND() (frontend/src/lib/money.ts).
     */
    public static function tnd(float|string|null $amount, string $currency = 'TND'): string
    {
        return number_format((float) $amount, 3, ',', ' ') . ' ' . $currency;
    }
}

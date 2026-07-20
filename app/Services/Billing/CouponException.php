<?php

namespace App\Services\Billing;

/**
 * Coupon invalide au moment de l'application. Le `reason` est un code stable
 * (not_found, inactive, expired, exhausted, below_min, already_applied) que la
 * couche HTTP traduit en message client.
 */
class CouponException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}

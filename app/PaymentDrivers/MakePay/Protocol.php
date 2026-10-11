<?php
namespace App\PaymentDrivers\MakePay;

final class Protocol
{
    public static function verify(string $raw, string $header, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || !preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/D', $header, $m)) return false;
        if (abs(($now ?? time()) - (int) $m[1]) > 300) return false;
        return hash_equals(hash_hmac('sha256', $m[1].'.'.$raw, $secret), $m[2]);
    }
    public static function amount($value): string
    {
        $s = (string) $value;
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $s) || (float) $s <= 0) throw new \InvalidArgumentException('Invalid USD checkout amount.');
        return number_format((float) $s, 2, '.', '');
    }
    public static function matches(array $event, array $saved, string $hash): bool
    {
        $link = $event['paymentLink'] ?? [];
        try {
            return ($link['uid'] ?? '') === ($saved['uid'] ?? null)
                && ($link['merchantOrderId'] ?? '') === 'ninja_'.$hash
                && ($link['currency'] ?? '') === 'USD'
                && self::amount($link['amount'] ?? '') === ($saved['amount'] ?? null)
                && is_string($event['session']['id'] ?? null) && $event['session']['id'] !== '';
        } catch (\Throwable) { return false; }
    }
}

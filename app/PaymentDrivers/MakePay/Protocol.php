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
    public static function amount($value, int $precision = 2): string
    {
        $s = (string) $value;
        if ($precision < 0 || $precision > 8 || !preg_match('/^\d+(?:\.\d{1,8})?$/D', $s) || strlen($s) > 30 || !preg_match('/[1-9]/', $s)) throw new \InvalidArgumentException('Invalid checkout amount.');
        [$whole, $fraction] = array_pad(explode('.', $s, 2), 2, '');
        if (strlen(rtrim($fraction, '0')) > $precision) throw new \InvalidArgumentException('Amount exceeds the invoice currency precision.');
        $whole = ltrim($whole, '0') ?: '0';
        return $whole.($precision ? '.'.str_pad(substr($fraction, 0, $precision), $precision, '0') : '');
    }
    public static function matches(array $event, array $saved, string $hash): bool
    {
        $link = $event['paymentLink'] ?? [];
        try {
            return ($link['uid'] ?? '') === ($saved['uid'] ?? null)
                && ($link['merchantOrderId'] ?? '') === 'ninja_'.$hash
                && ($link['fiatCurrency'] ?? (($link['currency'] ?? '') === 'USD' ? 'USD' : null)) === ($saved['currency'] ?? null)
                && self::amount($link['fiatAmount'] ?? $link['amount'] ?? '', $saved['precision'] ?? 2) === ($saved['amount'] ?? null)
                && is_string($event['session']['id'] ?? null) && $event['session']['id'] !== '';
        } catch (\Throwable) { return false; }
    }
}

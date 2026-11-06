<?php
defined('_JEXEC') or die('Restricted access');

/** Per-environment, expiring callback authorization; never contains raw secrets. */
final class TeyaVirtuemartOnboardingState
{
    public static function intents($method)
    {
        $value = $method->teya_onboarding ?? '';
        $state = is_array($value) ? $value : json_decode((string) $value, true);
        return is_array($state) ? $state : array();
    }

    public static function begin($method, $environment)
    {
        $nonce = bin2hex(random_bytes(32));
        $state = self::intents($method);
        $state[$environment] = array('hash'=>hash('sha256', $nonce), 'expires'=>time()+1800);
        $method->teya_onboarding = json_encode($state, JSON_THROW_ON_ERROR);
        return $nonce;
    }

    public static function validate($method, $environment, $nonce, $raw)
    {
        $intent = self::intents($method)[$environment] ?? array();
        if (!is_string($nonce) || !preg_match('/^[a-f0-9]{64}$/D', $nonce)
            || !isset($intent['hash']) || !hash_equals($intent['hash'], hash('sha256', $nonce))) {
            throw new RuntimeException('Unknown Teya connection attempt.');
        }
        if (isset($intent['completed'])) {
            if (!hash_equals($intent['completed'], hash('sha256', $raw))) {
                throw new RuntimeException('Teya connection attempt already completed.');
            }
            return false;
        }
        if (($intent['expires'] ?? 0) < time()) { throw new RuntimeException('Teya connection attempt expired.'); }
        return true;
    }

    public static function complete($method, $environment, $raw)
    {
        $state = self::intents($method);
        $state[$environment]['completed'] = hash('sha256', $raw);
        $method->teya_onboarding = json_encode($state, JSON_THROW_ON_ERROR);
    }

    public static function invalidate($method, $environment)
    {
        $state = self::intents($method);
        unset($state[$environment]);
        $method->teya_onboarding = json_encode($state, JSON_THROW_ON_ERROR);
    }
}

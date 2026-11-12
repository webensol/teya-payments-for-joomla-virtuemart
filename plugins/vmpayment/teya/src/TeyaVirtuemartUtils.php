<?php
/**
 * @package    Teya Payments for VirtueMart
 * @author     Webensol
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or die('Restricted access');

use ParagonIE\ConstantTime\Base64;

final class TeyaVirtuemartUtils
{
    public const ENV_LIVE = 'live';
    public const ENV_TEST = 'test';
    public const VERSION = '1.0.1';

    private const ZERO_DECIMAL_CURRENCIES = array(
        'ISK', 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG',
        'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    );

    private const THREE_DECIMAL_CURRENCIES = array('BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND');

    private const NO_FRACTIONAL_MAJOR_UNIT_CURRENCIES = array('HUF');

    private const SUPPORTED_LANGUAGES = array(
        'en-GB' => 'en-GB',
        'hu-HU' => 'hu-HU',
        'is-IS' => 'is-IS',
        'cs-CZ' => 'cs-CZ',
        'hr-HR' => 'hr-HR',
        'sk-SK' => 'sk-SK',
        'pt-PT' => 'pt-PT',
        'pt-BR' => 'pt-BR',
        'de-DE' => 'de-DE',
        'es-ES' => 'es-ES',
        'fr-FR' => 'fr-FR',
        'it-IT' => 'it-IT',
        'pl-PL' => 'pl-PL',
    );

    private const API_BASE_URLS = array(
        self::ENV_TEST => 'https://api.teya.xyz',
        self::ENV_LIVE => 'https://api.teya.com',
    );

    private const IDENTITY_BASE_URLS = array(
        self::ENV_TEST => 'https://id.teya.xyz',
        self::ENV_LIVE => 'https://id.teya.com',
    );

    private const BUSINESS_BASE_URLS = array(
        self::ENV_TEST => 'https://business.teya.xyz',
        self::ENV_LIVE => 'https://business.teya.com',
    );

    private const REGISTRATION_PUBLIC_KEY_URLS = array(
        self::ENV_TEST => 'https://cdn.teya.xyz/dynamic/4eb143f2/public-key',
        self::ENV_LIVE => 'https://cdn.teya.com/dynamic/4eb143f2/public-key',
    );

    public static function normalizeEnvironment($environment)
    {
        if (!in_array($environment, array(self::ENV_TEST, self::ENV_LIVE), true)) {
            throw new RuntimeException('Invalid Teya environment.');
        }
        return $environment;
    }

    public static function environmentFromParams($params)
    {
        return !empty($params->sandbox) ? self::ENV_TEST : self::ENV_LIVE;
    }

    public static function apiBaseUrl($environment)
    {
        return self::API_BASE_URLS[self::normalizeEnvironment($environment)];
    }

    public static function identityTokenUrl($environment)
    {
        return self::IDENTITY_BASE_URLS[self::normalizeEnvironment($environment)] . '/oauth/v2/oauth-token';
    }

    public static function businessRegistrationUrl($environment)
    {
        return self::BUSINESS_BASE_URLS[self::normalizeEnvironment($environment)] . '/ecom/plugin-registration';
    }

    public static function registrationPublicKeyUrl($environment)
    {
        return self::REGISTRATION_PUBLIC_KEY_URLS[self::normalizeEnvironment($environment)];
    }

    public static function currentRegistrationEndpoint()
    {
        return 'v1/ecommerce/registrations/current';
    }

    public static function toMinorUnits($amount, $currency)
    {
        $currency = strtoupper((string) $currency);
        $amount = (float) $amount;
        if (in_array($currency, self::NO_FRACTIONAL_MAJOR_UNIT_CURRENCIES, true)) {
            $amount = round($amount);
        }
        if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int) round($amount);
        }
        if (in_array($currency, self::THREE_DECIMAL_CURRENCIES, true)) {
            return (int) round($amount * 1000);
        }
        return (int) round($amount * 100);
    }

    public static function checkoutLanguage()
    {
        $language = JFactory::getLanguage();
        $tag = $language->get('tag');
        return isset(self::SUPPORTED_LANGUAGES[$tag]) ? self::SUPPORTED_LANGUAGES[$tag] : 'en-GB';
    }

    public static function truncateDescription($description, $maxLength = 300)
    {
        $description = trim(strip_tags((string) $description));
        if (function_exists('mb_strlen') && mb_strlen($description) > $maxLength) {
            return mb_substr($description, 0, max(0, $maxLength - 3)) . '...';
        }
        if (strlen($description) > $maxLength) {
            return substr($description, 0, max(0, $maxLength - 3)) . '...';
        }
        return $description;
    }

    public static function createIdempotencyKey()
    {
        if (function_exists('random_bytes')) {
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }

    public static function decodeBase64($value)
    {
        // Preserve support for wrapped keys and signatures with omitted padding.
        $encoded = preg_replace('/\s+/', '', (string) $value);
        $paddedLength = intdiv(strlen($encoded) + 3, 4) * 4;
        $encoded = str_pad($encoded, $paddedLength, '=');
        try {
            return Base64::decode($encoded, true);
        } catch (RangeException $exception) {
            return false;
        }
    }

    public static function derPublicKeyToPem($base64DerKey)
    {
        // Teya supplies a Base64 DER public key. PEM uses the same DER bytes in a text envelope.
        $der = self::decodeBase64($base64DerKey);
        if ($der === false) {
            throw new RuntimeException('Invalid base64 public key.');
        }
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(Base64::encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function redact(array $data)
    {
        foreach ($data as $key => $value) {
            if (in_array((string) $key, array('access_token', 'test_access_token', 'client_secret', 'test_client_secret', 'email', 'name', 'phone_number', 'billing_address', 'Authorization', 'authorization', 'payment_webhook_public_key', 'test_payment_webhook_public_key'), true)) {
                $data[$key] = '[redacted]';
            } elseif (is_object($value)) {
                $data[$key] = self::redact((array) $value);
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }
        return $data;
    }
}

<?php
/**
 * @package    Teya Payments for VirtueMart
 * @author     Webensol
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or defined('ABSPATH') or die('Restricted access');

use ParagonIE\ConstantTime\Base64;

final class TeyaVirtuemartCredentials
{
    public static function keys()
    {
        return array(
            'client_id',
            'client_secret',
            'payment_webhook_public_key',
            'access_token',
            'access_token_expires_at',
            'access_token_scopes',
            'test_client_id',
            'test_client_secret',
            'test_payment_webhook_public_key',
            'test_access_token',
            'test_access_token_expires_at',
            'test_access_token_scopes',
        );
    }

    public static function value($params, $name, $environment)
    {
        $key = TeyaVirtuemartUtils::normalizeEnvironment($environment) === TeyaVirtuemartUtils::ENV_TEST ? 'test_' . $name : $name;
        return self::decrypt(isset($params->$key) ? (string) $params->$key : '', $key);
    }

    private const PREFIX = 'teya:v1:';

    private static function encryptionKey()
    {
        $secret = (string) JFactory::getConfig()->get('secret');
        if (strlen($secret) < 16) {
            throw new RuntimeException('A valid Joomla site secret is required to protect Teya credentials.');
        }
        return hash_hkdf('sha256', $secret, 32, 'teya-virtuemart-credentials-v1');
    }

    public static function encrypt($value, $field)
    {
        $value = (string) $value;
        if ($value === '') { return ''; }
        if (strpos($value, self::PREFIX) === 0) {
            self::decrypt($value, $field);
            return $value;
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag, $field, 16);
        if ($cipher === false) { throw new RuntimeException('Teya credential encryption failed.'); }
        // Store the binary AES-GCM IV, authentication tag and ciphertext as Base64 text.
        return self::PREFIX . Base64::encode($iv . $tag . $cipher);
    }

    public static function decrypt($value, $field)
    {
        if (strpos($value, self::PREFIX) !== 0) { return $value; }
        // Decode the stored envelope before authenticating and decrypting it with AES-GCM.
        $bytes = TeyaVirtuemartUtils::decodeBase64(substr($value, strlen(self::PREFIX)));
        if ($bytes === false || strlen($bytes) < 29) { throw new RuntimeException('Invalid encrypted Teya credential.'); }
        $plain = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA,
            substr($bytes, 0, 12), substr($bytes, 12, 16), $field);
        if ($plain === false) { throw new RuntimeException('Cannot decrypt Teya credentials. Restore the original Joomla site secret or reconnect Teya.'); }
        return $plain;
    }

    public static function protect($params)
    {
        $result = clone $params;
        foreach (array('client_secret', 'access_token', 'test_client_secret', 'test_access_token') as $key) {
            if (isset($result->$key)) { $result->$key = self::encrypt($result->$key, $key); }
        }
        return $result;
    }

    public static function protectedKeys()
    {
        return array_merge(self::keys(), array('teya_onboarding', 'teya_connection_revision'));
    }

    public static function isConnected($params, $environment)
    {
        return self::value($params, 'client_id', $environment) !== ''
            && self::value($params, 'client_secret', $environment) !== ''
            && self::value($params, 'payment_webhook_public_key', $environment) !== '';
    }
}

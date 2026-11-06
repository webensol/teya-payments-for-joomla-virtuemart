<?php
/**
 * @package    Teya Payments for VirtueMart
 */
defined('_JEXEC') or die('Restricted access');

final class TeyaVirtuemartWebhookVerifier
{
    public static function verify($payload, $signature, $publicKey)
    {
        if ($signature === '') {
            throw new RuntimeException('Missing Teya signature.');
        }
        $pem = strpos($publicKey, 'BEGIN PUBLIC KEY') !== false ? $publicKey : TeyaVirtuemartUtils::derPublicKeyToPem($publicKey);
        $decodedSignature = base64_decode((string) $signature, true);
        if ($decodedSignature === false) {
            throw new RuntimeException('Invalid Teya signature encoding.');
        }
        $resource = openssl_pkey_get_public($pem);
        if (!$resource) {
            throw new RuntimeException('Invalid Teya webhook public key.');
        }
        $result = openssl_verify((string) $payload, $decodedSignature, $resource, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            throw new RuntimeException('Teya signature verification failed.');
        }
    }
}

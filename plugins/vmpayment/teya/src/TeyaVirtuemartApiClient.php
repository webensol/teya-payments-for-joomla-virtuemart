<?php
/**
 * @package    Teya Payments for VirtueMart
 * @author     Webensol
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or defined('ABSPATH') or die('Restricted access');

require_once __DIR__ . '/TeyaVirtuemartLanguage.php';

class TeyaVirtuemartApiException extends RuntimeException
{
    private $statusCode;
    private $responseBody;

    public function __construct($message, $statusCode = 0, array $responseBody = array())
    {
        parent::__construct($message, (int) $statusCode);
        $this->statusCode = (int) $statusCode;
        $this->responseBody = $responseBody;
    }

    public function getStatusCode()
    {
        return $this->statusCode;
    }

    public function getResponseBody()
    {
        return $this->responseBody;
    }
}

final class TeyaVirtuemartApiClient
{
    public const SESSION_READ_SCOPE = 'checkout/sessions/id/get';
    private $debug;
    private $logger;

    public function __construct($debug, callable $logger)
    {
        $this->debug = (bool) $debug;
        $this->logger = $logger;
    }

    public function createCheckoutSession(array $payload, $accessToken, $environment, $idempotencyKey = null)
    {
        return $this->requestJson('POST', 'v2/checkout/sessions', $environment, $accessToken, $payload, array('LINE_ITEMS_TOTAL_MISMATCH', 'INVALID_LINE_ITEMS', 'BAD_REQUEST'), $idempotencyKey);
    }

    public function createRefund(array $payload, $accessToken, $environment, $idempotencyKey = null)
    {
        return $this->requestJson('POST', 'v2/refunds', $environment, $accessToken, $payload, array(), $idempotencyKey);
    }

    public function getCheckoutSession($sessionId, $accessToken, $environment)
    {
        if (!is_string($sessionId) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $sessionId)) {
            throw new InvalidArgumentException('Invalid checkout session identifier.');
        }
        return $this->requestJson('GET', 'v2/checkout/sessions/' . rawurlencode($sessionId), $environment, $accessToken, null, array(), null, 10);
    }

    public function deleteCurrentRegistration($accessToken, $environment)
    {
        $this->requestJson('DELETE', TeyaVirtuemartUtils::currentRegistrationEndpoint(), $environment, $accessToken, null, array());
    }

    public function requestAccessToken($clientId, $clientSecret, $environment, $sessionRead = false)
    {
        $body = http_build_query(array(
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'checkout/sessions ecommerce/registrations/current refunds' . ($sessionRead ? ' ' . self::SESSION_READ_SCOPE : ''),
        ));
        $response = $this->request('POST', TeyaVirtuemartUtils::identityTokenUrl($environment), array('Content-Type: application/x-www-form-urlencoded'), $body);
        if (empty($response['body']['access_token'])) {
            throw new RuntimeException(TeyaVirtuemartLanguage::text('API_TOKEN_ERROR'));
        }
        return $response['body'];
    }

    public function fetchRegistrationPublicKey($environment)
    {
        $response = $this->request('GET', TeyaVirtuemartUtils::registrationPublicKeyUrl($environment), array('User-Agent: Teya-Signature-Validator/1.0'), null, false);
        $publicKey = trim((string) $response['raw']);
        if ($publicKey === '') {
            throw new RuntimeException(TeyaVirtuemartLanguage::text('API_KEY_ERROR'));
        }
        return $publicKey;
    }

    private function requestJson($method, $endpoint, $environment, $accessToken, ?array $payload, array $allowedErrorCodes, $idempotencyKey = null, $timeout = 30)
    {
        $url = TeyaVirtuemartUtils::apiBaseUrl($environment) . '/' . ltrim($endpoint, '/');
        $headers = array(
            'Accept: application/json',
            'Authorization: Bearer ' . $accessToken,
        );
        if ($method !== 'GET') { $headers[] = 'Idempotency-Key: ' . ($idempotencyKey ?: TeyaVirtuemartUtils::createIdempotencyKey()); }
        $body = null;
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        }
        $response = $this->request($method, $url, $headers, $body, true, $timeout);
        $response['body']['_retry_after'] = $response['retry_after'] ?? 0;
        $teyaCode = isset($response['body']['code']) ? $response['body']['code'] : null;
        if ($response['status'] >= 400 && !in_array($teyaCode, $allowedErrorCodes, true)) {
            $message = TeyaVirtuemartLanguage::text('API_ERROR', $response['status']);
            throw new TeyaVirtuemartApiException($message, $response['status'], is_array($response['body']) ? $response['body'] : array());
        }
        $body = is_array($response['body']) ? $response['body'] : array();
        $body['_http_status'] = $response['status'];
        return $body;
    }

    private function request($method, $url, array $headers = array(), $body = null, $decodeJson = true, $timeout = 30)
    {
        // Bodies can contain checkout access URLs, customer data and provider-echoed secrets.
        $logUrl = preg_replace('#(/v2/checkout/sessions/)[^/?]+#', '$1{session_id}', $url);
        $this->log('Teya HTTP request: ' . json_encode(array('method'=>$method,'url'=>$logUrl)));
        if (!preg_grep('/^User-Agent:/i', $headers)) { $headers[] = 'User-Agent: Teya-VirtueMart/' . TeyaVirtuemartUtils::VERSION; }
        $retryAfter = '';
        $diagnosticHeaders = array();
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$retryAfter, &$diagnosticHeaders) {
                self::captureDiagnosticHeader($diagnosticHeaders, $line);
                if (stripos($line, 'Retry-After:') === 0) { $retryAfter = trim(substr($line, 12)); }
                return strlen($line);
            });
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $raw = curl_exec($ch);
            if ($raw === false) {
                throw new RuntimeException(TeyaVirtuemartLanguage::text('API_NETWORK_ERROR'));
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        } else {
            $context = stream_context_create(array('http' => array(
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body === null ? '' : $body,
                'timeout' => $timeout,
                'follow_location' => 0,
                'ignore_errors' => true,
            )));
            $raw = @file_get_contents($url, false, $context);
            if ($raw === false) {
                throw new RuntimeException(TeyaVirtuemartLanguage::text('API_NETWORK_ERROR'));
            }
            // An explicit variable read is required by legacy PHP; use the modern accessor on PHP 8.4+.
            $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : (${'http_response_header'} ?? array());
            foreach ($responseHeaders ?? array() as $line) { self::captureDiagnosticHeader($diagnosticHeaders, $line); if (stripos($line, 'Retry-After:') === 0) { $retryAfter = trim(substr($line, 12)); } }
            $status = 200;
            if (isset($responseHeaders[0]) && preg_match('#\s(\d{3})\s#', $responseHeaders[0], $match)) {
                $status = (int) $match[1];
            }
        }
        if (strpos($url, '/v2/refunds') !== false) {
            $this->log('Teya refund response metadata: ' . json_encode(self::responseMetadata($status, (string) $raw, $diagnosticHeaders)));
        }
        $decoded = $decodeJson ? json_decode((string) $raw, true) : null;
        if ($decodeJson && !is_array($decoded)) {
            $decoded = array();
        }
        $summary = array('status'=>$status,'body'=>'[response body omitted]');
        if ($status >= 400 && is_array($decoded)) { $summary['error'] = self::safeApiError($decoded); }
        $this->log('Teya HTTP response: ' . json_encode($summary));
        return array('status' => $status, 'body' => $decoded, 'raw' => (string) $raw, 'retry_after' => self::retryDelay($retryAfter));
    }

    /** Recognized diagnostics only: descriptions may echo arbitrary personal data or secrets. */
    public static function safeApiError(array $body)
    {
        $code = $body['code'] ?? ($body['error'] ?? '');
        $codes = array('BAD_REQUEST', 'INVALID_LINE_ITEMS', 'LINE_ITEMS_TOTAL_MISMATCH', 'UNAUTHORIZED', 'FORBIDDEN',
            'NOT_FOUND', 'CONFLICT', 'TOO_MANY_REQUESTS', 'INTERNAL_SERVER_ERROR', 'SERVICE_UNAVAILABLE',
            'invalid_client', 'invalid_scope', 'invalid_grant', 'invalid_request', 'unsupported_grant_type');
        $safeCode = in_array($code, $codes, true) ? $code : 'UNRECOGNIZED_ERROR';
        $description = $body['description'] ?? ($body['error_description'] ?? '');
        $safeDescription = 'Provider error details omitted.';
        if (is_string($description)) {
            $known = array('Invalid Email Address', 'Invalid email address', 'Bad Request', 'Unauthorized', 'Forbidden',
                'Invalid scope', 'Invalid client', 'Session not found', 'Transaction not found', 'Invalid line items',
                'Line items total mismatch', 'Too many requests', 'Service unavailable', 'Internal server error');
            if (in_array($description, $known, true)) { $safeDescription = $description; }
            elseif (preg_match('/^Invalid email address(?:[.:]|$)/i', $description)) {
                $safeDescription = 'Invalid Email Address';
            }
            elseif (preg_match('/^Invalid value for field [\'"](customer|amount|currency|line_items|language|billing_address|customer_success_email|merchant_success_email)[\'"]\./', $description, $match)) {
                $safeDescription = 'Invalid value for field ' . $match[1] . '.';
            } elseif (strpos($description, 'Fractional amounts are not allowed for currency HUF') === 0) {
                $safeDescription = 'Fractional amounts are not allowed for currency HUF.';
            }
        }
        return array('code'=>$safeCode, 'description'=>$safeDescription);
    }

    // Explicit allowlist: never capture cookies, authentication headers or response text.
    public static function captureDiagnosticHeader(array &$headers, $line)
    {
        if (preg_match('#^HTTP/\S+\s+\d{3}#i', $line)) { $headers = array(); return; }
        $parts = explode(':', $line, 2);
        if (count($parts) !== 2) { return; }
        $name = strtolower(trim($parts[0]));
        $value = trim($parts[1]);
        if ($name === 'content-type') {
            $value = strtolower(trim(explode(';', $value, 2)[0]));
            if (preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#D', $value) && strlen($value) <= 80) { $headers[$name] = $value; }
        } elseif (in_array($name, array('x-request-id', 'x-correlation-id', 'cf-ray', 'x-amzn-requestid', 'x-amz-apigw-id'), true)
            && preg_match('/^[a-zA-Z0-9._:=+-]{1,128}$/D', $value)) {
            $headers[$name] = $value;
        }
    }

    public static function responseMetadata($status, $raw, array $headers)
    {
        $safe = array();
        foreach ($headers as $name => $value) { self::captureDiagnosticHeader($safe, $name . ': ' . $value); }
        json_decode($raw);
        $format = trim($raw) === '' ? 'empty' : (json_last_error() === JSON_ERROR_NONE ? 'json' : 'non-json');
        return array('status' => (int) $status, 'body_bytes' => strlen($raw), 'body_format' => $format, 'headers' => $safe);
    }

    public static function retryDelay($value)
    {
        if ($value==='') { return 0; }
        return ctype_digit($value) ? (int) $value : max(0,(int) strtotime($value)-time());
    }

    private function log($message)
    {
        if ($this->debug) {
            call_user_func($this->logger, $message);
        }
    }
}

<?php
// Real local HTTP/cURL traffic, no external provider and no merchant credentials.
define('_JEXEC', 1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
class TeyaVirtuemartUtils {
    public const VERSION = '1.0.2';
    public static $base;
    public static function apiBaseUrl($environment) { return self::$base; }
    public static function identityTokenUrl($environment) { return self::$base . '/oauth/token'; }
}
require dirname(__DIR__) . '/plugins/vmpayment/teya/src/TeyaVirtuemartApiClient.php';
function transportCheck($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
transportCheck((bool) $server, 'Local HTTP server could not start');
TeyaVirtuemartUtils::$base = 'http://' . stream_socket_get_name($server, false);
$pid = pcntl_fork();
transportCheck($pid >= 0, 'Local HTTP server could not fork');
if ($pid === 0) {
    for ($index = 0; $index < 10; ++$index) {
        $socket = stream_socket_accept($server, 10);
        if (!$socket) { exit(1); }
        $requestLine = trim(fgets($socket)); $headers = array();
        while (($line = fgets($socket)) !== false && trim($line) !== '') {
            $parts = explode(':', $line, 2); $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        $body = ''; $length = (int) ($headers['content-length'] ?? 0);
        while (strlen($body) < $length) { $body .= fread($socket, $length - strlen($body)); }
        $payload = json_decode($body, true);
        $status = '201 Created'; $type = 'application/json'; $extra = '';
        $response = json_encode(array('status'=>'SUCCESS','seen'=>array('headers'=>$headers,'body'=>$payload)));
        if (strpos($requestLine, '/oauth/token') !== false) {
            parse_str($body, $form);
            if (($form['client_secret'] ?? '') !== 'FIXTURE_SECRET' || ($form['grant_type'] ?? '') !== 'client_credentials') { exit(2); }
            $expectedScope='checkout/sessions ecommerce/registrations/current refunds'.($index===4 ? ' checkout/sessions/id/get' : '');
            if (($form['scope'] ?? '')!==$expectedScope) { exit(3); }
            $status = '200 OK'; $response = '{"access_token":"FIXTURE_TOKEN","expires_in":600}';
        } elseif (strpos($requestLine, 'GET /v2/checkout/sessions/fixture-session ')===0) {
            if ($body!=='' || isset($headers['idempotency-key']) || ($headers['authorization'] ?? '')!=='Bearer FIXTURE_TOKEN') { exit(4); }
            $status='200 OK'; $response=json_encode(array('session_id'=>'fixture-session','session_status'=>'COMPLETED','payment_status'=>'SUCCESS','transaction_id'=>'fixture-transaction','type'=>'SALE','amount'=>array('value'=>1200,'currency'=>'EUR')));
        } elseif (strpos($requestLine, 'POST /v2/checkout/sessions ')===0) {
            $status='400 Bad Request';
            $description=($payload['test_case'] ?? '')==='unsafe' ? 'Invalid Email Address: PRIVATE_CUSTOMER FIXTURE_TOKEN PRIVATE_BODY' : 'Invalid Email Address';
            $response=json_encode(array('code'=>'BAD_REQUEST','description'=>$description,'customer'=>array('email'=>'PRIVATE_CUSTOMER')));
        } elseif (($payload['transaction_id'] ?? '') === 'denied') {
            $status = '403 Forbidden'; $type = 'text/html';
            $response = '<html>PRIVATE_PROVIDER_RESPONSE</html>';
            $extra = "X-Request-ID: fixture-request-123\r\nSet-Cookie: PRIVATE_COOKIE\r\n";
        } elseif (($payload['transaction_id'] ?? '') === 'limited') {
            $status = '429 Too Many Requests'; $response = '{}'; $extra = "Retry-After: 7200\r\n";
        } elseif (($payload['transaction_id'] ?? '') === 'redirect') {
            $status = '302 Found'; $response = '{}'; $extra = "Location: https://example.invalid/must-not-follow\r\n";
        }
        fwrite($socket, "HTTP/1.1 $status\r\nContent-Type: $type\r\n" . $extra . 'Content-Length: ' . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
        fclose($socket);
    }
    fclose($server); exit(0);
}
$logs = array();
try {
    $api = new TeyaVirtuemartApiClient(true, function ($message) use (&$logs) { $logs[] = $message; });
    $token = $api->requestAccessToken('fixture-client', 'FIXTURE_SECRET', 'test');
    transportCheck($token['access_token'] === 'FIXTURE_TOKEN', 'OAuth token response');
    $payload = array('transaction_id'=>'fixture-transaction','amount'=>300000,'reason'=>'HikaShop refund');
    $refund = $api->createRefund($payload, $token['access_token'], 'test', 'fixture-refund-key');
    transportCheck($refund['_http_status'] === 201 && $refund['seen']['body'] === $payload, 'Refund body and status: HTTP '.($refund['_http_status'] ?? 0).'; body matches='.(int)(($refund['seen']['body'] ?? null)===$payload));
    $sent = $refund['seen']['headers'];
    transportCheck($sent['authorization'] === 'Bearer FIXTURE_TOKEN' && $sent['idempotency-key'] === 'fixture-refund-key', 'Authentication/idempotency headers');
    transportCheck($sent['user-agent'] === 'Teya-VirtueMart/1.0.2' && $sent['content-type'] === 'application/json', 'User-Agent and JSON headers');
    try { $api->createRefund(array('transaction_id'=>'denied'), 'FIXTURE_TOKEN', 'test', 'denied-refund-key'); throw new LogicException('403 accepted'); }
    catch (TeyaVirtuemartApiException $exception) {
        transportCheck($exception->getStatusCode() === 403 && $exception->getMessage() === TeyaVirtuemartLanguage::text('API_ERROR', 403), 'Safe localized API error');
    }
    $response = $api->createRefund(array('transaction_id'=>'redirect'), 'FIXTURE_TOKEN', 'test', 'redirect-refund-key');
    transportCheck($response['_http_status'] === 302, 'Redirect is never followed');
    $api->requestAccessToken('fixture-client', 'FIXTURE_SECRET', 'test', true);
    $session=$api->getCheckoutSession('fixture-session', 'FIXTURE_TOKEN', 'test');
    transportCheck($session['_http_status']===200 && $session['payment_status']==='SUCCESS','Authenticated GET session lookup');
    $api->createCheckoutSession(array('customer'=>(object)array('email'=>'PRIVATE_CUSTOMER')), 'FIXTURE_TOKEN', 'test', 'fixture-checkout-key');
    $api->createCheckoutSession(array('test_case'=>'unsafe'), 'FIXTURE_TOKEN', 'test', 'fixture-unsafe-key');
    $silentLogs=array();
    (new TeyaVirtuemartApiClient(false,function ($message) use (&$silentLogs) { $silentLogs[]=$message; }))
        ->createCheckoutSession(array(), 'FIXTURE_TOKEN', 'test', 'fixture-silent-key');
    transportCheck($silentLogs===array(),'Diagnostics disabled when debug is off');
    try { $api->createRefund(array('transaction_id'=>'limited'), 'FIXTURE_TOKEN', 'test', 'rate-limit-key'); throw new LogicException('429 accepted'); }
    catch (TeyaVirtuemartApiException $exception) {
        transportCheck($exception->getResponseBody()['_retry_after'] === 7200, 'Retry-After survives the transport');
    }
    $logged=implode(' ', $logs);
    transportCheck(strpos($logged,'BAD_REQUEST')!==false && strpos($logged,'Invalid Email Address')!==false,'Recognized error code/description missing from debug log');
    transportCheck(strpos($logged,'fixture-session')===false && strpos($logged,'{session_id}')!==false,'Session identifier must be masked in request log');
    foreach (array('FIXTURE_SECRET','FIXTURE_TOKEN','PRIVATE_PROVIDER_RESPONSE','PRIVATE_COOKIE','PRIVATE_CUSTOMER','PRIVATE_BODY') as $secret) {
        transportCheck(strpos(implode('\n', $logs), $secret) === false, 'Sensitive data reached logs');
    }
    transportCheck(strpos(implode('\n', $logs), 'fixture-request-123') !== false, 'Safe diagnostic request ID missing');
    foreach (array(
        array('code'=>'PRIVATE_SECRET','description'=>'PRIVATE_CUSTOMER'),
        array('code'=>'BAD_REQUEST','description'=>array('PRIVATE_CUSTOMER')),
        array('code'=>'BAD_REQUEST','description'=>"Invalid value for field 'customer'. PRIVATE_BODY"),
        array('code'=>'BAD_REQUEST','description'=>'Fractional amounts are not allowed for currency HUF, input: PRIVATE_BODY')
    ) as $error) {
        $safe=json_encode(TeyaVirtuemartApiClient::safeApiError($error));
        foreach (array('PRIVATE_SECRET','PRIVATE_CUSTOMER','PRIVATE_BODY') as $private) {
            transportCheck(strpos($safe,$private)===false,'Unrecognized/echoed provider data reached diagnostic');
        }
    }
    try { $api->getCheckoutSession('../private', 'FIXTURE_TOKEN', 'test'); throw new LogicException('Unsafe session path accepted'); }
    catch (InvalidArgumentException $expected) {}
    pcntl_waitpid($pid, $status);
    transportCheck(pcntl_wexitstatus($status) === 0, 'Local HTTP fixture failed');
    echo 'PASS: real HTTP OAuth/refund/session GET, scope selection, safe error diagnostics, debug-off silence, no redirects/notices on PHP ' . PHP_VERSION . PHP_EOL;
} finally {
    fclose($server);
    if (pcntl_waitpid($pid, $status, WNOHANG) === 0) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
}

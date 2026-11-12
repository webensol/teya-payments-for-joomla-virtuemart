<?php
require dirname(__DIR__) . '/vendor/autoload.php';
// Offline regression tests: no merchant credentials or external payment calls.
define('_JEXEC', 1);
set_error_handler(function ($severity, $message, $file, $line) { if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); } return false; });
class vmPSPlugin {}
class JFactory {
    public static $db;
    public static function getConfig() { return new class { function get($key) { return 'offline-vm-site-secret-for-tests'; } }; }
    public static function getDBO() { return self::$db; }
    public static function getLanguage() { return new class { function get($key) { return 'en-GB'; } }; }
    public static function getApplication() { return new class { function isClient($client) { return false; } }; }
    public static function getUser() { return (object) array('guest' => true); }
}
class shopFunctions {
    static function getCurrencyByID($id, $field) { return array(1 => 'EUR', 2 => 'HUF', 3 => 'KWD')[$id] ?? null; }
}
require (getenv('TEYA_VM_SOURCE') ?: dirname(__DIR__) . '/plugins/vmpayment/teya') . '/teya.php';
$checks = 0;
function check($ok, $message) {
    global $checks;
    ++$checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
}
function rejects($callback, $message) {
    try { $callback(); } catch (RuntimeException $e) { check(true, $message); return; }
    check(false, $message);
}
function invoke($object, $method, ...$args) {
    return (new ReflectionMethod(plgVmPaymentTeya::class, $method))->invoke($object, ...$args);
}
class TestQuery {
    public $sets = array();
    function update($v) { return $this; }
    function select($v) { return $this; }
    function from($v) { return $this; }
    function where($v) { return $this; }
    function order($v) { return $this; }
    function set($v) { $this->sets[] = $v; return $this; }
    function __toString() { return 'query'; }
}
class VmModel {
    public static $statusOK = true;
    public static $updates = 0;
    static function getModel($name) { return new self(); }
    function updateStatusForOneOrder(...$args) { ++self::$updates; return self::$statusOK; }
}
class TestDB {
    public $row;
    public $locked = false;
    public $allowLock = true;
    public $failWrite = false;
    public $pendingRefunds = 0;
    private $query;
    private $snapshot;
    public $params = 'countries=[1,2]|test_client_id="id"|test_client_secret="secret"|client_secret="live-secret"';
    function getAffectedRows() { return 1; }
    function transactionStart() { $this->snapshot = clone $this->row; }
    function transactionCommit() { $this->snapshot = null; }
    function transactionRollback() { $this->row = $this->snapshot; }
    function getQuery($new) { return new TestQuery(); }
    function execute() {
        if ($this->query instanceof TestQuery) {
            foreach ($this->query->sets as $set) {
                $pair = explode(' = ', $set, 2);
                $this->params = stripslashes(substr($pair[1], 1, -1));
            }
        }
        return true;
    }
    function quote($s) { return "'" . addslashes($s) . "'"; }
    function quoteName($s) { return '`' . $s . '`'; }
    function setQuery($q, ...$rest) { $this->query = $q; return $this; }
    function loadResult() {
        if (strpos($this->query, 'GET_LOCK') !== false) { $this->locked = $this->allowLock; return $this->allowLock ? 1 : 0; }
        if (strpos($this->query, 'RELEASE_LOCK') !== false) { $this->locked = false; return 1; }
        if (strpos($this->query, 'SELECT COUNT(*)') !== false) { return $this->pendingRefunds; }
        if (strpos($this->query, 'SELECT payment_params') !== false) { return $this->params; }
        throw new RuntimeException('Unexpected query');
    }
    function loadObject() { return $this->row ? clone $this->row : null; }
    function updateObject($table, &$values, $key) { if ($this->failWrite) { return false; } foreach ((array) $values as $k => $v) { $this->row->$k = $v; } return true; }
}
class TestApi {
    public $responses = array();
    public $calls = array();
    public $checkoutResponses = array();
    public $checkoutCalls = array();
    public $tokenRequests = 0;
    public $tokenScopes = array();
    public $sessionCalls = array();
    public $sessionResponses = array();
    public $publicKey;
    function fetchRegistrationPublicKey($env) { return $this->publicKey; }
    function requestAccessToken(...$args) { ++$this->tokenRequests; $this->tokenScopes[] = $args[3] ?? false; return array('access_token' => 'new-token', 'expires_in' => 30); }
    function createCheckoutSession($payload, $token, $environment, $key) {
        $this->checkoutCalls[] = compact('payload','token','environment','key');
        check(JFactory::$db->locked, 'checkout request protected by lock');
        $stored = json_decode(JFactory::$db->row->teya_response, true);
        check(($stored['_checkout_key'] ?? '') === $key, 'checkout key persisted before API');
        $result = array_shift($this->checkoutResponses);
        if ($result instanceof Throwable) { throw $result; }
        return $result;
    }
    function getCheckoutSession($session, $token, $environment) {
        $this->sessionCalls[] = array($session, $environment);
        $response = array_shift($this->sessionResponses);
        if ($response instanceof Throwable) { throw $response; }
        return $response;
    }
    function createRefund($payload, $token, $environment, $key) {
        $this->calls[] = compact('payload', 'token', 'environment', 'key');
        $state = TeyaVirtuemartRefundState::load(JFactory::$db->row);
        check(JFactory::$db->locked, 'refund API protected by lock');
        check(isset($state['pending']) && $state['pending']['key'] === $key, 'persist refund before API call');
        $next = array_shift($this->responses);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    }
}
class TestPlugin extends plgVmPaymentTeya {
    protected $_tablename = 'test_payment';
    public $api;
    function __construct() { $this->api = new TestApi(); }
    protected function apiClient($method) { return $this->api; }
    public function writeLog($message) {}
    protected function renderPluginName($method) { return 'Teya'; }
    public function getEmailCurrency($method) { return 1; }
    public function getDataByOrderId($id) { return JFactory::$db->row; }
    public function getVmPluginMethod($id, $cache=true) { return (object) array('virtuemart_paymentmethod_id'=>3,'payment_element'=>'teya','status_refund'=>'R'); }
    public function selectedThisElement($element) { return $element==='teya'; }
    protected function storePSPluginInternalData($values, ...$args) {
        if (!isset($values['id'])) { JFactory::$db->row = (object) array('id'=>1); }
        foreach ($values as $k=>$v) { JFactory::$db->row->$k = $v; }
    }
}
function payment() {
    return (object) array('id' => 1, 'virtuemart_order_id' => 12, 'virtuemart_paymentmethod_id' => 3,
        'order_number' => 'ORDER12', 'teya_transaction_id' => 'tr_original', 'teya_environment' => 'test',
        'teya_response' => json_encode(array('event' => 'payment.succeeded.v1', 'data' => array('status' => 'SUCCESS',
            'transaction_id' => 'tr_original', 'amount' => array('value' => 10000, 'currency' => 'EUR')))));
}
function readyRetry() {
    $state = TeyaVirtuemartRefundState::load(JFactory::$db->row);
    $state['pending']['next_attempt'] = 0;
    JFactory::$db->row->teya_refund_response = json_encode($state);
}
$method = (object) array('virtuemart_paymentmethod_id' => 3, 'test_client_id' => 'fake-id', 'test_client_secret' => 'fake-secret', 'test_access_token' => 'offline-test-token', 'test_access_token_expires_at' => time() + 1000);
$plugin = new TestPlugin();
$db = JFactory::$db = new TestDB();
$db->row = payment();
foreach ((array) $method as $key=>$value) { $db->params .= '|'.$key.'='.json_encode($value); }
$plugin->api->responses[] = array('_http_status' => 202);
$r = invoke($plugin, 'processRefund', 12, $method, '25.00', 'request-one');
check($r['pending'] && $r['remaining'] === 10000, '202 does not reduce refundable balance');
check(!$db->locked, 'lock released after processing');
rejects(fn() => invoke($plugin, 'processRefund', 12, $method, '99.00', 'request-two'), 'pending blocks a different request reference');
check(count($plugin->api->calls) === 1, 'cooldown prevents premature resubmit');
readyRetry();
$plugin->api->responses[] = array('_http_status' => 201, 'status' => 'SUCCESS', 'transaction_id' => 'refund1', 'refund_amount' => array('amount' => 2500, 'currency' => 'EUR'));
$r = invoke($plugin, 'processRefund', 12, $method, null, null, true);
check(!$r['pending'] && $r['remaining'] === 7500, 'partial refund confirmed');
check($plugin->api->calls[0]['key'] === $plugin->api->calls[1]['key'] && $plugin->api->calls[0]['payload'] === $plugin->api->calls[1]['payload'], 'retry preserves exact key and payload');
invoke($plugin, 'processRefund', 12, $method, '25.00', 'request-one');
check(count($plugin->api->calls) === 2, 'replayed completed request does not refund twice');
rejects(fn() => invoke($plugin, 'processRefund', 12, $method, '76', 'too-much'), 'over-refund rejected');
check(count($plugin->api->calls) === 2, 'over-refund does not call API');
$plugin->api->responses[] = array('_http_status' => 201, 'status' => 'SUCCESS', 'transaction_id' => 'refund2');
$r = invoke($plugin, 'processRefund', 12, $method);
check($r['remaining'] === 0 && $plugin->api->calls[2]['payload']['amount'] === 7500, 'full refund uses remaining paid amount');
invoke($plugin, 'processRefund', 12, $method);
check(count($plugin->api->calls) === 3, 'fully refunded payment cannot be refunded again');
check(count(TeyaVirtuemartRefundState::load($db->row)['refunds']) === 2, 'multiple refunds retained for audit');
$db->row = payment();
$plugin->api->responses[] = new RuntimeException('timeout');
$r = invoke($plugin, 'processRefund', 12, $method, '10', 'timeout-key');
check($r['pending'], 'timeout preserves pending request');
readyRetry();
$plugin->api->responses[] = new TeyaVirtuemartApiException('rate limited', 429);
$r = invoke($plugin, 'processRefund', 12, $method, null, null, true);
check($r['pending'] && end($plugin->api->calls)['key'] === 'timeout-key', 'rate limit preserves original key');
readyRetry();
$plugin->api->responses[] = new TeyaVirtuemartApiException('invalid transaction', 400);
rejects(fn() => invoke($plugin, 'processRefund', 12, $method, null, null, true), 'definitive rejection surfaced');
check(empty(TeyaVirtuemartRefundState::load($db->row)['pending']), 'definitive rejection clears pending request');
$db->allowLock = false;
rejects(fn() => invoke($plugin, 'processRefund', 12, $method, '1', 'concurrent'), 'concurrent request blocked');
$db->allowLock = true;
foreach (array('0','-1','abc','1.001','101') as $value) {
    rejects(fn() => TeyaVirtuemartRefundState::amount($value, 'EUR', 10000), 'invalid EUR refund ' . $value);
}
rejects(fn() => TeyaVirtuemartRefundState::amount('1.5', 'HUF', 10000), 'fractional HUF refund rejected');
check(TeyaVirtuemartRefundState::amount('1.123','KWD',10000) === 1123, 'three decimal refund');
check(invoke($plugin, 'canManage', 'vm.orders.edit') === false, 'anonymous frontend cannot manage refunds');
check(invoke($plugin, 'webhookOutcome', array('event' => 'refund.succeeded.v1', 'data' => array('status' => 'SUCCESS', 'transaction_id' => 'x'))) === 'unsupported', 'refund event cannot mark payment paid');
check(invoke($plugin, 'webhookOutcome', array('event' => 'payment.succeeded.v1', 'data' => array('status' => 'FAILURE', 'transaction_id' => 'x'))) !== 'success', 'conflicting event rejected');
$order = array('details' => array('BT' => (object) array('order_currency' => 2, 'order_total' => 1360, 'order_number' => 'HUF1', 'email' => array('test@example.invalid'))),
    'items' => array((object) array('product_quantity' => 2, 'product_final_price' => 500, 'order_item_name' => 'Item')));
$payload = TeyaVirtuemartCheckoutBuilder::build((object) array(), $order, (object) array('payment_currency' => 1), array('success_url'=>'https://example.invalid/ok', 'failure_url'=>'https://example.invalid/fail', 'cancel_url'=>'https://example.invalid/cancel'));
check($payload['amount'] === array('currency'=>'HUF','value'=>136000), 'order currency wins over method currency');
check(is_object($payload['customer']) && $payload['customer']->email === 'test@example.invalid', 'customer JSON object with scalar email');
check(array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_price'], $payload['line_items'])) === 136000, 'line items reconcile including fees and rounding');
$order['details']['BT']->order_total = 1359.5;
rejects(fn() => TeyaVirtuemartCheckoutBuilder::build((object) array(), $order, (object) array(), array()), 'fractional HUF total cannot silently overcharge');
$log = TeyaVirtuemartUtils::redact(array('body' => array('client_secret'=>'SECRET', 'access_token'=>'TOKEN', 'customer'=>(object) array('email'=>'PRIVATE'))));
check(strpos(json_encode($log), 'SECRET') === false && strpos(json_encode($log), 'TOKEN') === false && strpos(json_encode($log), 'PRIVATE') === false, 'logs redact credentials and customer objects');
$key = openssl_pkey_new(array('private_key_bits'=>2048));
$public = openssl_pkey_get_details($key)['key'];
openssl_sign('payload', $sig, $key, OPENSSL_ALGO_SHA256);
TeyaVirtuemartWebhookVerifier::verify('payload', base64_encode($sig), $public);
check(true, 'RSA signature verified');
TeyaVirtuemartWebhookVerifier::verify('payload', rtrim(base64_encode($sig), '='), $public);
check(true, 'unpadded RSA signature remains supported');
TeyaVirtuemartWebhookVerifier::verify('payload', chunk_split(base64_encode($sig), 48), $public);
check(true, 'wrapped RSA signature remains supported');
$derText = preg_replace('/-----[^-]+-----|\s+/', '', $public);
check(TeyaVirtuemartUtils::derPublicKeyToPem($derText) === $public, 'DER public key produces the same PEM');
rejects(fn() => TeyaVirtuemartWebhookVerifier::verify('payload', '%%%invalid%%%', $public), 'invalid signature encoding rejected');

rejects(fn() => TeyaVirtuemartWebhookVerifier::verify('tampered', base64_encode($sig), $public), 'tampered webhook rejected');
// Reproduce the pre-existing configuration serialization failure using dummy data.
$pem = "-----BEGIN PUBLIC KEY-----\nDUMMY\n-----END PUBLIC KEY-----\n";
$legacy = '"' . str_replace('"', '\\"', $pem) . '"';
check(json_decode($legacy) === null, 'legacy multiline serialization is invalid JSON');
check(json_decode(json_encode($pem)) === $pem, 'JSON encoding preserves multiline key');
check(invoke($plugin, 'webhookOutcome', array('event'=>'refund.failed.v1', 'data'=>array('status'=>'FAILED'))) === 'unsupported', 'refund failure cannot cancel a paid order');
// Expired bearer token: the same durable refund must be retried after refresh.
$db->row = payment();
$plugin->api->responses[] = new TeyaVirtuemartApiException('expired', 401);
$plugin->api->responses[] = array('_http_status'=>201,'status'=>'SUCCESS');
$r = invoke($plugin, 'processRefund', 12, $method, '1', 'token-retry');
$lastCalls = array_slice($plugin->api->calls, -2);
check($lastCalls[0]['key'] === $lastCalls[1]['key'], '401 retry preserves refund reference');
check($plugin->api->tokenRequests === 1, '401 obtains fresh token');
$persisted = array();
foreach (explode('|',$db->params) as $part) { $pair=explode('=',$part,2); if (count($pair)===2) { $persisted[$pair[0]]=json_decode($pair[1],true); } }
check($persisted['test_access_token_expires_at'] < time()+30, 'short token is not cached beyond expiry');
check(strpos($db->params, 'countries=[1,2]') !== false && strpos($db->params, 'live-secret') !== false, 'token update retains restrictions and other environment');
// Durable checkout recovery across two separate invocations.
$method->payment_currency = 1;
$method->status_pending = 'U';
$details = (object) array('virtuemart_order_id'=>12,'order_number'=>'ORDER12','order_currency'=>1,'order_total'=>10);
$checkoutOrder = array('details'=>array('BT'=>$details));
$request = array('amount'=>array('value'=>1000,'currency'=>'EUR'),'line_items'=>array(),'customer'=>(object)array('email'=>'offline@example.invalid'));
$db->row = null;
$plugin->api->checkoutResponses[] = new RuntimeException('connection interrupted');
rejects(fn() => invoke($plugin, 'createOrResumeCheckout', $checkoutOrder, $method, 'test', $request), 'checkout timeout surfaced');
$plugin->api->checkoutResponses[] = array('session_id'=>'session1','session_url'=>'https://checkout.teya.xyz/session1');
$r = invoke($plugin, 'createOrResumeCheckout', $checkoutOrder, $method, 'test', $request);
check($plugin->api->checkoutCalls[0]['key'] === $plugin->api->checkoutCalls[1]['key'], 'checkout retry after interruption preserves key');
invoke($plugin, 'createOrResumeCheckout', $checkoutOrder, $method, 'test', $request);
check(count($plugin->api->checkoutCalls) === 2, 'refresh reuses existing unexpired session');
$db->row->teya_session_id = 'session1';
$beforeConfirmation = clone $db->row;
$method->status_success = 'C';
$method->status_failed = 'X';
$staleSession=json_decode($db->row->teya_response,true); $staleSession['_created_at']=time()-3600;
$db->row->teya_response=json_encode($staleSession); $before=count($plugin->api->checkoutCalls);
invoke($plugin,'createOrResumeCheckout',$checkoutOrder,$method,'test',$request);
check(count($plugin->api->checkoutCalls)===$before,'age alone cannot create another payable session');
rejects(fn()=>invoke($plugin,'createOrResumeCheckout',$checkoutOrder,$method,'live',$request),'unresolved checkout cannot switch environment');
$event = array('event'=>'payment.succeeded.v1', 'data'=>array('status'=>'SUCCESS', 'session_id'=>'session1','transaction_id'=>'tr_paid','amount'=>array('value'=>1000,'currency'=>'EUR')));
$bad = $event;
$bad['data']['amount']['value'] = 1;
rejects(fn() => invoke($plugin,'applyPaymentNotification',$method,$bad,'test'), 'underpayment webhook rejected');
rejects(fn() => invoke($plugin,'applyPaymentNotification',$method,$event,'live'), 'wrong-environment webhook rejected');
VmModel::$statusOK = false;
rejects(fn() => invoke($plugin,'applyPaymentNotification',$method,$event,'test'), 'order status persistence failure surfaced');
check(!isset(json_decode($db->row->teya_response,true)['event']), 'failed order status rolls back payment event');
VmModel::$statusOK = true;
invoke($plugin,'applyPaymentNotification',$method,$event,'test');
$count = VmModel::$updates;
invoke($plugin,'applyPaymentNotification',$method,$event,'test');
check(VmModel::$updates === $count, 'duplicate webhook does not send another order update');
rejects(fn() => invoke($plugin,'createOrResumeCheckout',$checkoutOrder,$method,'test',$request), 'paid order cannot start another checkout');
check(!$db->locked, 'locks released after callback and checkout failures');
// Reconciliation must operate on stored provider state and converge with callbacks.
check(invoke($plugin, 'reconcileReturn', $method, 12) === 'success' && !$plugin->api->sessionCalls,
    'webhook-first return does not contact Teya again');
$db->row = clone $beforeConfirmation;
$pending = json_decode($db->row->teya_response, true);
$pending['_request']['merchant_reference'] = 'joomla_virtuemart#ORDER12';
$db->row->teya_response = json_encode($pending);
$beforeConfirmation = clone $db->row;
$session = array('_http_status'=>200, 'session_id'=>'session1', 'session_status'=>'COMPLETED', 'payment_status'=>'SUCCESS',
    'type'=>'SALE', 'transaction_id'=>'tr_paid', 'merchant_reference'=>'joomla_virtuemart#ORDER12',
    'amount'=>array('value'=>1000, 'currency'=>'EUR'), 'customer'=>array('email'=>'PRIVATE_RETURN_EMAIL'));
foreach (array(
    array('session_id'=>'foreign-session'), array('type'=>'AUTHORIZATION'),
    array('merchant_reference'=>'foreign-order'), array('transaction_id'=>''),
    array('amount'=>array('value'=>999, 'currency'=>'EUR')),
    array('amount'=>array('value'=>1000, 'currency'=>'HUF')),
) as $override) {
    $db->row = clone $beforeConfirmation;
    $plugin->api->sessionResponses[] = array_replace($session, $override);
    rejects(fn()=>invoke($plugin, 'reconcileReturn', $method, 12), 'mismatched lookup evidence is rejected');
    check(VmModel::$updates === $count, 'mismatched lookup never changes the order');
}
$db->row = clone $beforeConfirmation;
$plugin->api->sessionResponses[] = array_replace($session, array('session_status'=>'ACTIVE', 'payment_status'=>'PENDING'));
check(invoke($plugin, 'reconcileReturn', $method, 12) !== 'success', 'inconclusive lookup remains pending');
$calls = count($plugin->api->sessionCalls);
invoke($plugin, 'reconcileReturn', $method, 12);
check(count($plugin->api->sessionCalls) === $calls, 'pending lookup is throttled durably');
$db->row = clone $beforeConfirmation;
$plugin->api->sessionResponses[] = new TeyaVirtuemartApiException('Insufficient scope', 403);
rejects(fn()=>invoke($plugin, 'reconcileReturn', $method, 12), 'missing session scope cannot confirm payment');
check(VmModel::$updates === $count, 'permission failure preserves pending order');
$db->row = clone $beforeConfirmation;
$plugin->api->sessionResponses[] = new TeyaVirtuemartApiException('Expired', 401);
$plugin->api->sessionResponses[] = $session;
check(invoke($plugin, 'reconcileReturn', $method, 12) === 'success', 'authenticated successful session confirms payment');
check(end($plugin->api->tokenScopes) === true && end($plugin->api->sessionCalls) === array('session1', 'test'),
    'lookup uses session read scope and original checkout environment');
check(strpos($db->params, 'test_access_token_scopes="checkout\/sessions\/id\/get"') !== false, 'scoped token cache persists its scope');
check(strpos($db->row->teya_response, 'PRIVATE_RETURN_EMAIL') === false, 'lookup customer details are not stored');
check(invoke($plugin, 'refundBalance', $db->row) === $session['amount'], 'return confirmation is eligible for refund');
$count = VmModel::$updates;
invoke($plugin, 'applyPaymentNotification', $method, $event, 'test');
invoke($plugin, 'reconcileReturn', $method, 12);
check(VmModel::$updates === $count, 'late webhook and repeated returns cannot duplicate confirmation');

$emailDetails = clone $details;
$emailDetails->email = 'receipt@example.invalid';
$emailOrder = array('details'=>array('BT'=>$emailDetails));
$emailUrls = array('success_url'=>'https://shop.example/ok', 'failure_url'=>'https://shop.example/fail', 'cancel_url'=>'https://shop.example/back');
$emailMethod = clone $method;
check(!isset(TeyaVirtuemartCheckoutBuilder::build(null, $emailOrder, $emailMethod, $emailUrls)['customer_success_email']),
    'Teya customer receipt defaults off');
$emailMethod->customer_success_email = 1;
check(TeyaVirtuemartCheckoutBuilder::build(null, $emailOrder, $emailMethod, $emailUrls)['customer_success_email'] === $emailDetails->email,
    'explicit opt-in uses the existing billing email for the Teya receipt');

// Internal status synchronization cannot trigger a second refund or authorize a new one.
$db->row=payment();
$db->row->teya_refund_response=json_encode(array('refunds'=>array(array('key'=>'confirmed','amount'=>10000))));
$orderStatus=(object) array('virtuemart_order_id'=>12,'virtuemart_paymentmethod_id'=>3,'order_status'=>'R');
$before=count($plugin->api->calls);
check($plugin->plgVmOnUpdateOrderPayment($orderStatus,'C')===true,'confirmed full refund permits status synchronization');
check(count($plugin->api->calls)===$before,'status synchronization never refunds twice');
$db->row=payment();
check($plugin->plgVmOnUpdateOrderPayment($orderStatus,'C')===false,'unauthorized status change cannot initiate refund');
// Authorization failures stop automatic recovery, preserve requests, and never expose provider text.
$db->row = payment();
$plugin->api->responses[] = new TeyaVirtuemartApiException('SECRET_TOKEN customer@example.invalid',403);
$result = invoke($plugin,'processRefund',12,$method,'1','authorization-block');
check($result['pending'] && $result['blocked'],'403 blocks automatic recovery');
$before=count($plugin->api->calls);
invoke($plugin,'processRefund',12,$method,null,null,true);
check(count($plugin->api->calls)===$before,'blocked refund makes no automatic request');
check(strpos($db->row->teya_refund_response,'SECRET_TOKEN')===false,'provider text is not persisted');
$attempt=TeyaVirtuemartRefundState::load($db->row)['pending'];
$plugin->api->responses[]=array('_http_status'=>201,'status'=>'SUCCESS','private'=>'SECRET_RESPONSE');
$result=invoke($plugin,'processRefund',12,$method,null,null,true,true);
$last=end($plugin->api->calls);
check(!$result['pending'] && $last['key']===$attempt['key'] && $last['payload']===$attempt['payload'],'explicit recovery reuses exact request');
check(strpos($db->row->teya_refund_response,'SECRET_RESPONSE')===false,'provider success body is not persisted');
$db->row=payment();
$plugin->api->responses[]=new TeyaVirtuemartApiException('limited',429,array('_retry_after'=>7200));
invoke($plugin,'processRefund',12,$method,'1','rate-limited');
check(TeyaVirtuemartRefundState::load($db->row)['pending']['next_attempt']>=time()+7199,'Retry-After survives durable recovery');
$db->row=payment(); $db->failWrite=true; $before=count($plugin->api->calls);
rejects(fn()=>invoke($plugin,'processRefund',12,$method,'1','failed-write'),'failed refund persistence surfaces error');
check(count($plugin->api->calls)===$before,'no API call after failed pending write');
$db->failWrite=false;
// Signed registration requires the exact method/environment attempt; completion and credentials share one save.
$plugin->api->publicKey=$public;
$fresh=clone $method;
$nonce=TeyaVirtuemartOnboardingState::begin($fresh,'test');
invoke($plugin,'savePaymentParams',3,$fresh,'test');
$raw=json_encode(array('client_id'=>'new-id','client_secret'=>'NEW_SECRET','payment_webhook_public_key'=>$public));
openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256);
$before=$plugin->api->tokenRequests;
invoke($plugin,'handleRegistrationNotification',$method,'test',$raw,base64_encode($signature),$nonce);
check($plugin->api->tokenRequests===$before+1,'signed matching registration obtains one token');
check(strpos($db->params,'NEW_SECRET')===false,'registration secret encrypted before persistence');
invoke($plugin,'handleRegistrationNotification',$method,'test',$raw,base64_encode($signature),$nonce);
check($plugin->api->tokenRequests===$before+1,'duplicate callback does not refresh token');
rejects(fn()=>invoke($plugin,'handleRegistrationNotification',$method,'live',$raw,base64_encode($signature),$nonce),'callback cannot cross environments');
rejects(fn()=>invoke($plugin,'handleRegistrationNotification',$method,'test',$raw,base64_encode($signature),str_repeat('0',64)),'unknown callback attempt rejected');
rejects(fn()=>invoke($plugin,'handleRegistrationNotification',$method,'test',$raw.'x',base64_encode($signature),$nonce),'tampered callback rejected');
$expired=clone $method;
$n=TeyaVirtuemartOnboardingState::begin($expired,'test');
$intents=TeyaVirtuemartOnboardingState::intents($expired); $intents['test']['expires']=time()-1; $expired->teya_onboarding=json_encode($intents);
rejects(fn()=>TeyaVirtuemartOnboardingState::validate($expired,'test',$n,$raw),'expired callback rejected');
TeyaVirtuemartOnboardingState::invalidate($expired,'test');
rejects(fn()=>TeyaVirtuemartOnboardingState::validate($expired,'test',$n,$raw),'disconnect invalidates pending callback');
$db->pendingRefunds=1;
rejects(fn()=>invoke($plugin,'handleDisconnect',$method,'test'),'unresolved refund blocks disconnect');
$db->pendingRefunds=0;

// Companion parameter decoding and uninstall guard are read-only.
class JPlugin {}
define('JPATH_SITE', dirname(getenv('TEYA_VM_SOURCE') ?: dirname(__DIR__) . '/plugins/vmpayment/teya', 3));
require JPATH_SITE . '/plugins/system/teyavirtuemart/teyavirtuemart.php';
class CompanionDB {
    public $params;
    function setQuery($query, ...$rest) {}
    function loadObject() { return (object) array('type'=>'plugin','folder'=>'vmpayment','element'=>'teya'); }
    function getPrefix() { return 'test_'; }
    function getTableList() { return array('test_virtuemart_paymentmethods'); }
    function loadColumn() { return array($this->params); }
}
$companion = new plgSystemTeyaVirtuemart();
$companionDB = JFactory::$db = new CompanionDB();
$companionDB->params = 'test_client_id="id"|test_client_secret="secret"|test_payment_webhook_public_key="key"';
rejects(fn() => $companion->onExtensionBeforeUninstall(747), 'connected registration blocks plugin uninstall without remote deletion');
$companionDB->params = 'test_client_id=""|test_client_secret=""|test_payment_webhook_public_key=""';
$companion->onExtensionBeforeUninstall(747);
check(true, 'disconnected plugin can be uninstalled');
$dummy = (object) array('payment_params' => 'payment_webhook_public_key=' . json_encode($pem));
(new ReflectionMethod(plgSystemTeyaVirtuemart::class, 'bindPaymentParams'))->invoke($companion, $dummy);
check($dummy->payment_webhook_public_key === $pem, 'companion correctly decodes multiline public keys');


$protected = TeyaVirtuemartCredentials::protect((object) array('client_secret'=>'ENCRYPT_ME','test_access_token'=>'TOKEN_SECRET','sandbox'=>1));
check(strpos(serialize($protected),'ENCRYPT_ME')===false && strpos(serialize($protected),'TOKEN_SECRET')===false,'VM secrets encrypted');
check(TeyaVirtuemartCredentials::value($protected,'client_secret','live')==='ENCRYPT_ME','VM encrypted secret readable');
check(TeyaVirtuemartCredentials::value($protected,'access_token','test')==='TOKEN_SECRET','VM encrypted token readable');
rejects(function () use ($protected) { TeyaVirtuemartCredentials::decrypt($protected->client_secret,'test_client_secret'); },'VM ciphertext bound to field');



// Existing encrypted credentials retain the same envelope, key derivation and field binding.
$legacyField = 'test_client_secret';
$legacyPlain = 'legacy-credential-' . str_repeat('x', 30);
$legacyIv = str_repeat("\x01", 12);
$legacyTag = '';
$legacyKey = hash_hkdf('sha256', 'offline-vm-site-secret-for-tests', 32, 'teya-virtuemart-credentials-v1');
$legacyCipher = openssl_encrypt($legacyPlain, 'aes-256-gcm', $legacyKey, OPENSSL_RAW_DATA, $legacyIv, $legacyTag, $legacyField, 16);
$legacyEnvelope = 'teya:v1:' . base64_encode($legacyIv . $legacyTag . $legacyCipher);
check(TeyaVirtuemartCredentials::decrypt($legacyEnvelope, $legacyField) === $legacyPlain, 'existing credential envelope remains readable');
check(TeyaVirtuemartCredentials::encrypt($legacyEnvelope, $legacyField) === $legacyEnvelope, 'saving an existing envelope preserves it');
$newEnvelope = TeyaVirtuemartCredentials::encrypt($legacyPlain, $legacyField);
$newBytes = base64_decode(substr($newEnvelope, strlen('teya:v1:')), true);
$oldReader = openssl_decrypt(substr($newBytes, 28), 'aes-256-gcm', $legacyKey, OPENSSL_RAW_DATA, substr($newBytes, 0, 12), substr($newBytes, 12, 16), $legacyField);
check($oldReader === $legacyPlain, 'new credentials remain compatible with the original reader');
rejects(fn() => TeyaVirtuemartCredentials::decrypt('teya:v1:%%%invalid%%%', $legacyField), 'malformed encrypted envelope rejected');

foreach (array('', 'a', 'ab', 'abc', 'abcd', random_bytes(32), implode('', array_map('chr', range(0, 255)))) as $binary) {
    $encoded = ParagonIE\ConstantTime\Base64::encode($binary);
    check($encoded === base64_encode($binary), 'encoding matches existing persisted representation');
    check(TeyaVirtuemartUtils::decodeBase64($encoded) === $binary, 'encoded data round trip');
    check(TeyaVirtuemartUtils::decodeBase64(rtrim($encoded, '=')) === $binary, 'unpadded data round trip');
}
foreach (array('A', 'A===', 'abc!', '=YQ==', 'YQ===') as $invalid) {
    check(TeyaVirtuemartUtils::decodeBase64($invalid) === false, 'malformed encoding rejected');
}

foreach (array($plugin,$companion) as $target) {
    $testMethod=(object) array('client_secret'=>'DB_SECRET','access_token'=>'DB_TOKEN','access_token_expires_at'=>time()+1000);
    JFactory::$db=new TestDB();
    (new ReflectionMethod($target,'savePaymentParams'))->invoke($target,3,$testMethod,'live',false,false);
    check(strpos(JFactory::$db->params,'DB_SECRET')===false && strpos(JFactory::$db->params,'DB_TOKEN')===false,'VM persistence never writes plaintext secret');
    $encrypted=TeyaVirtuemartCredentials::protect($testMethod);
    check((new ReflectionMethod($target,'getAccessToken'))->invoke($target,3,$encrypted,'live')==='DB_TOKEN','VM cached encrypted token decrypted before API');
}
rejects(fn()=>TeyaVirtuemartUtils::normalizeEnvironment('production'),'unknown environment cannot silently choose live');
echo "PASS: $checks offline checks\n";

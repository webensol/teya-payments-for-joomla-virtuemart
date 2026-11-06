<?php
/**
 * @package    Teya Payments for VirtueMart
 * @author     Webensol
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or die('Restricted access');

require_once __DIR__.'/src/TeyaVirtuemartCompatibility.php';

if (!class_exists('vmPSPlugin')) {
    require_once JPATH_ADMINISTRATOR . '/components/com_virtuemart/plugins/vmpsplugin.php';
}

require_once __DIR__ . '/src/TeyaVirtuemartUtils.php';
require_once __DIR__ . '/src/TeyaVirtuemartCredentials.php';
require_once __DIR__ . '/src/TeyaVirtuemartRefundState.php';
require_once __DIR__ . '/src/TeyaVirtuemartApiClient.php';
require_once __DIR__ . '/src/TeyaVirtuemartWebhookVerifier.php';
require_once __DIR__ . '/src/TeyaVirtuemartCheckoutBuilder.php';
require_once __DIR__ . '/src/TeyaVirtuemartOnboardingRenderer.php';
require_once __DIR__ . '/src/TeyaVirtuemartOnboardingState.php';

class plgVmPaymentTeya extends vmPSPlugin
{
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);
        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = $this->getVarsToPush();
        $this->addVarsToPushCore($varsToPush, 1);
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    public function getTableSQLFields()
    {
        return array(
            'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'order_number' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'varchar(600)',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'smallint(1)',
            'email_currency' => 'smallint(1)',
            'teya_session_id' => 'varchar(128)',
            'teya_transaction_id' => 'varchar(128)',
            'teya_environment' => 'varchar(16)',
            'teya_response' => 'text',
            'teya_refund_key' => 'varchar(128)',
            'teya_refund_status' => 'varchar(32)',
            'teya_refund_amount' => 'int(11)',
            'teya_refund_currency' => 'char(3)',
            'teya_refund_response' => 'text',
        );
    }

    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Teya Payments for VirtueMart');
    }

    public function getVarsToPush()
    {
        $vars = array(
            'checkConditionsCore' => array(1, 'int'),
            'sandbox' => array(1, 'int'),
            'merchant_success_email' => array('', 'char'),
            'customer_success_email' => array(0, 'int'),
            'post_success_payment' => array('redirect', 'char'),
            'total_line_item' => array(0, 'int'),
            'debug' => array(0, 'int'),
            'teya_onboarding' => array('', 'char'),
            'payment_currency' => array('', 'int'),
            'status_pending' => array('U', 'char'),
            'status_success' => array('C', 'char'),
            'status_failed' => array('X', 'char'),
            'status_refund' => array('R', 'char'),
        );
        foreach (TeyaVirtuemartCredentials::keys() as $key) {
            $vars[$key] = array('', 'char');
        }
        return $vars;
    }

    public function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->_currentMethod = $method;
        $this->loadTeyaLanguage();

        $environment = TeyaVirtuemartUtils::environmentFromParams($method);
        if (!TeyaVirtuemartCredentials::isConnected($method, $environment)) {
            JFactory::getApplication()->enqueueMessage(vmText::_('VMPAYMENT_TEYA_NOT_CONNECTED'), 'error');
            return false;
        }

        try {
            $method->payment_currency = $order['details']['BT']->order_currency;
            $urls = $this->buildCheckoutUrls($order);
            $payload = TeyaVirtuemartCheckoutBuilder::build($cart, $order, $method, $urls);
            if ($payload['amount']['value'] <= 0) {
                throw new RuntimeException('Teya requires a positive order total.');
            }
            $response = $this->createOrResumeCheckout($order, $method, $environment, $payload);

            // VirtueMart's normal cart persistence runs after this hook returns.
            $cart->setCartIntoSession();
            JFactory::getApplication()->redirect($response['session_url']);
        } catch (Exception $exception) {
            $this->writeLog('Teya checkout failed: ' . get_class($exception));
            JFactory::getApplication()->enqueueMessage(TeyaVirtuemartLanguage::text('CHECKOUT_ERROR'), 'error');
            return false;
        }
        return true;
    }

    public function plgVmOnPaymentNotification()
    {
        $this->loadTeyaLanguage();
        $input = JFactory::getApplication()->input;
        $methodId = $input->getInt('pm', 0);
        $action = $input->getCmd('teya_action', 'payment');
        if (!$methodId || !($method = $this->getVmPluginMethod($methodId, false)) || !$this->selectedThisElement($method->payment_element)) {
            $this->respond(404, 'Payment method not found');
        }
        $this->_currentMethod = $method;
        try {
            if (in_array($action, array('status', 'connect', 'disconnect', 'refund', 'refund_retry'), true)) {
                $permission = in_array($action, array('refund', 'refund_retry'), true) ? 'vm.orders.edit' : 'vm.paymentmethod.edit';
                if (!$this->canManage($permission)) {
                    $this->respond(403, 'Administrator permission required');
                }
            }
            if ($action === 'refund' || $action === 'refund_retry') {
                $this->handleAdminRefund($method, $action === 'refund_retry');
            }
            if ($action === 'connect') {
                if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->checkRequestToken('post')) {
                    $this->respond(403, 'Invalid session token');
                }
                $environment = TeyaVirtuemartUtils::normalizeEnvironment($input->getCmd('environment'));
                $result = $this->withConnectionLock($method, function ($fresh) use ($environment) {
                    $this->assertNoPendingRefunds($fresh);
                    $nonce = TeyaVirtuemartOnboardingState::begin($fresh, $environment);
                    $this->savePaymentParams($fresh->virtuemart_paymentmethod_id, $fresh, $environment);
                    return array('url'=>self::registrationUrl($fresh->virtuemart_paymentmethod_id, $environment, $nonce), 'attempt'=>hash('sha256', $nonce));
                });
                $this->respondJson(200, $result);
            }
            if ($action === 'registration') {
                $this->handleRegistrationNotification($method, $input->getCmd('environment', TeyaVirtuemartUtils::environmentFromParams($method)));
                $this->respond(200, 'OK');
            }
            if ($action === 'disconnect') {
                if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->checkRequestToken('post')) {
                    $this->respond(403, 'Invalid session token');
                }
                $this->handleDisconnect($method, $input->getCmd('environment', TeyaVirtuemartUtils::environmentFromParams($method)));
                $this->respondJson(200, array('connected' => false, 'environment'=>TeyaVirtuemartUtils::normalizeEnvironment($input->getCmd('environment'))));
            }
            if ($action === 'status') {
                $this->respondJson(200, $this->connectionStatus($method, $input->getCmd('environment', TeyaVirtuemartUtils::environmentFromParams($method))));
            }
            if ($action !== 'payment') {
                throw new RuntimeException('Unsupported Teya action.');
            }
            $this->handlePaymentNotification($method);
            $this->respond(200, 'OK');
        } catch (Exception $exception) {
            $this->writeLog('Teya operation failed: ' . get_class($exception) . '; code=' . (int) $exception->getCode());
            $this->respondJson(400, array('error' => TeyaVirtuemartLanguage::text('REQUEST_FAILED')));
        }
    }

    public function plgVmOnPaymentResponseReceived(&$html)
    {
        $this->loadTeyaLanguage();
        $input = JFactory::getApplication()->input;
        $orderNumber = $input->getString('on', '');
        $methodId = $input->getInt('pm', 0);
        if (!$methodId || !($method = $this->getVmPluginMethod($methodId))) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        if ($orderNumber === '') {
            return false;
        }
        // The full VirtueMart order model joins billing rows with a second user ID.
        // Authorization must use the owner on the order itself.
        $db = JFactory::getDBO();
        $db->setQuery('SELECT virtuemart_order_id, virtuemart_user_id, order_number'
            . ' FROM #__virtuemart_orders WHERE order_number = ' . $db->quote($orderNumber)
            . ' AND virtuemart_paymentmethod_id = ' . $methodId, 0, 1);
        $details = $db->loadObject();
        if (!$details || $details->order_number !== $orderNumber) {
            return false;
        }
        $orderId = (int) $details->virtuemart_order_id;

        $cart = VirtueMartCart::getCart();
        $sameCart = (int) $cart->virtuemart_order_id === $orderId && $cart->order_number === $orderNumber;
        $userId = (int) JFactory::getApplication()->getIdentity()->id;
        $ownerId = (int) $details->virtuemart_user_id;
        $isGuestCheckout = $ownerId === 0 && ($sameCart || (int) $cart->OrderIdOrderDone === $orderId);
        if (!$isGuestCheckout && ($ownerId === 0 || $ownerId !== $userId)) {
            return false;
        }

        $payment = $this->latestPayment($orderId);
        if (!$payment || (int) $payment->virtuemart_paymentmethod_id !== $methodId) {
            return false;
        }
        $this->_currentMethod = $method;
        $outcome = $this->webhookOutcome($this->decodeStoredResponse($payment));
        if ($outcome !== 'success') {
            try {
                $outcome = $this->reconcileReturn($method, $orderId);
            } catch (Throwable $exception) {
                $this->writeLog('Teya session verification deferred: ' . get_class($exception));
            }
        }

        if ($outcome === 'success') {
            if ($sameCart) {
                $this->emptyCart();
            }
            $html = '<div class="teya-payment-response"><h3>' . vmText::_('VMPAYMENT_TEYA_PAYMENT_CONFIRMED') . '</h3><p>' . vmText::_('VMPAYMENT_TEYA_PAYMENT_CONFIRMED_COPY') . '</p></div>';
        } elseif ($outcome === 'failure') {
            $html = '<div class="teya-payment-response"><h3>' . vmText::_('VMPAYMENT_TEYA_PAYMENT_FAILED') . '</h3><p>' . vmText::_('VMPAYMENT_TEYA_PAYMENT_FAILED_COPY') . '</p></div>';
        } else {
            $html = '<div class="teya-payment-response"><h3>' . vmText::_('VMPAYMENT_TEYA_PAYMENT_RECEIVED') . '</h3><p>' . vmText::_('VMPAYMENT_TEYA_PAYMENT_RECEIVED_COPY') . '</p></div>';
        }
        return true;
    }

    public function plgVmOnUserPaymentCancel()
    {
        $orderNumber = JFactory::getApplication()->input->getString('on', '');
        $methodId = JFactory::getApplication()->input->getInt('pm', 0);
        if (!$methodId || !($method = $this->getVmPluginMethod($methodId))) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $orderId = VirtueMartModelOrders::getOrderIdByOrderNumber($orderNumber);
        if ($orderId) {
            $payment = $this->getDataByOrderId($orderId);
            if (!$payment || (int) $payment->virtuemart_paymentmethod_id !== $methodId) {
                return false;
            }
            if ($payment && $this->webhookOutcome($this->decodeStoredResponse($payment)) === 'success') {
                $this->writeLog('Ignoring customer cancel for already paid Teya order ' . (int) $orderId);
                return true;
            }
            JFactory::getApplication()->enqueueMessage(vmText::_('VMPAYMENT_TEYA_PAYMENT_CANCELLED'), 'message');
        }
        return true;
    }

    public function plgVmOnUpdateOrderPayment(&$order, $old_order_status)
    {
        $method = $this->getVmPluginMethod($order->virtuemart_paymentmethod_id);
        if (!$method || !$this->selectedThisElement($method->payment_element)
            || empty($method->status_refund) || $order->order_status !== $method->status_refund
            || $old_order_status === $order->order_status) {
            return null;
        }
        // A confirmed full-refund ledger permits internal status synchronization only.
        $savedPayment = $this->latestPayment((int) $order->virtuemart_order_id);
        if ($savedPayment) {
            try {
                $paid = $this->refundBalance($savedPayment);
                $ledger = TeyaVirtuemartRefundState::load($savedPayment);
                if (!empty($ledger['refunds']) && empty($ledger['pending'])
                    && TeyaVirtuemartRefundState::refunded($ledger) === (int) $paid['value']) { return true; }
            } catch (RuntimeException $exception) { /* No confirmed refund evidence. */ }
        }
        if (!$this->canManage('vm.orders.edit')) {
            return false;
        }
        $this->_currentMethod = $method;
        try {
            $result = $this->processRefund((int) $order->virtuemart_order_id, $method);
            if ($result['pending']) {
                throw new RuntimeException(!empty($result['blocked']) ? 'Teya refused refund authorization. Automatic retries are paused.' : 'Refund outcome is not confirmed. Open the order payment panel to review it.');
            }
            return $result['remaining'] === 0;
        } catch (Exception $exception) {
            JFactory::getApplication()->enqueueMessage($exception->getMessage(), 'error');
            $this->writeLog('Teya refund failed: '.get_class($exception).'; code='.(int) $exception->getCode());
            return false;
        }
    }

    public function plgVmOnBeforePaymentMethodDelete($virtuemart_paymentmethod_id, &$do = true)
    {
        if (!$do || !($method = $this->getVmPluginMethod((int) $virtuemart_paymentmethod_id, false))) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return null;
        }
        try {
            $this->assertNoPendingRefunds($method);
            $this->disconnectPaymentMethodRegistrations($method);
        } catch (Exception $exception) {
            $do = false;
            $message = 'Teya could not safely disconnect this method. Resolve pending refunds and connection errors before deleting it.';
            $this->writeLog($message);
            JFactory::getApplication()->enqueueMessage($message, 'error');
            return false;
        }
        return true;
    }

    protected function checkConditions($cart, $method, $cart_prices)
    {
        return TeyaVirtuemartCredentials::isConnected($method, TeyaVirtuemartUtils::environmentFromParams($method))
            && parent::checkConditions($cart, $method, $cart_prices);
    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices, &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null;
        }
        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }
        $this->getPaymentCurrency($method);
        $paymentCurrencyId = $method->payment_currency;
    }

    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id)
    {
        if (!$this->selectedThisByMethodId($payment_method_id)) {
            return null;
        }
        if (!($payment = $this->latestPayment($virtuemart_order_id))) {
            return null;
        }
        $this->loadTeyaLanguage();
        $html = '<table class="adminlist table">' . "\n";
        $html .= $this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('VMPAYMENT_TEYA_PAYMENT_NAME', $payment->payment_name);
        $html .= $this->getHtmlRowBE('VMPAYMENT_TEYA_SESSION_ID', htmlspecialchars((string) $payment->teya_session_id, ENT_QUOTES, 'UTF-8'));
        $html .= $this->getHtmlRowBE('VMPAYMENT_TEYA_TRANSACTION_ID', htmlspecialchars((string) $payment->teya_transaction_id, ENT_QUOTES, 'UTF-8'));
        $html .= $this->getHtmlRowBE('VMPAYMENT_TEYA_ENVIRONMENT', htmlspecialchars((string) $payment->teya_environment, ENT_QUOTES, 'UTF-8'));
        $state = TeyaVirtuemartRefundState::load($payment);
        $escape = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
        $html .= $this->getHtmlRowBE('VMPAYMENT_TEYA_REFUND_STATUS', $escape($payment->teya_refund_status ?? ''));
        foreach ($state['refunds'] as $refund) {
            $html .= $this->getHtmlRowBE('VMPAYMENT_TEYA_REFUND_CONFIRMED', $escape(($refund['amount'] / TeyaVirtuemartUtils::toMinorUnits(1, $payment->teya_refund_currency)) . ' ' . $payment->teya_refund_currency . '; ' . ($refund['transaction_id'] ?? $refund['response']['transaction_id'] ?? $refund['key'])));
        }
        if ($this->canManage('vm.orders.edit') && $this->webhookOutcome($this->decodeStoredResponse($payment)) === 'success') {
            $paid = $this->refundBalance($payment);
            $remaining = max(0, (int) $paid['value'] - TeyaVirtuemartRefundState::refunded($state));
            $factor = TeyaVirtuemartUtils::toMinorUnits(1, $paid['currency']);
            $digits = (int) round(log10($factor));
            $display = number_format($remaining / $factor, $digits, '.', '');
            $url = rtrim(JUri::root(), '/') . '/administrator/index.php?option=com_virtuemart&teya_admin=1&pm=' . (int) $payment_method_id;
            $messages = TeyaVirtuemartLanguage::messages(array(
                'REFUND_PENDING', 'REFUND_BLOCKED', 'REFUND_CONFIRM', 'REFUND_CONFIRM_RETRY',
                'REFUND_ERROR', 'REFUND_REFRESH', 'REFUND_CONFIRMED', 'REFUND_STATUS_SYNC_ERROR',
            ));
            $panel = '<div class="teya-refund-panel" data-messages="' . $messages . '" data-url="' . $escape($url) . '" data-order="' . (int) $virtuemart_order_id
                . '" data-token="' . $escape($this->requestTokenName()) . '" data-key="' . TeyaVirtuemartUtils::createIdempotencyKey()
                . '" data-blocked="' . (!empty($state['pending']['blocked']) ? '1' : '0') . '" data-pending="' . (!empty($state['pending']) ? '1' : '0') . '">';
            $panel .= '<p>' . vmText::_('VMPAYMENT_TEYA_REFUND_REMAINING') . ': ' . $escape($display . ' ' . $paid['currency']) . '</p>';
            if (!empty($state['pending']['blocked'])) {
                $panel .= '<p>' . $escape(TeyaVirtuemartLanguage::text('REFUND_BLOCKED')) . '</p>';
            }
            if ($remaining > 0) {
                $panel .= '<label>' . vmText::_('VMPAYMENT_TEYA_REFUND_AMOUNT') . ' <input class="teya-refund-amount" type="text" inputmode="decimal" value="' . $escape($display) . '"></label> ';
                $panel .= '<button type="button" class="btn btn-primary teya-refund-submit">' . vmText::_('VMPAYMENT_TEYA_REFUND_BUTTON') . '</button> ';
                $panel .= '<button type="button" class="btn teya-refund-retry">' . vmText::_('VMPAYMENT_TEYA_REFUND_CHECK') . '</button>';
            }
            if ($remaining === 0 && ($payment->teya_refund_status ?? '') === 'status_pending') {
                $panel .= '<p>' . $escape(TeyaVirtuemartLanguage::text('REFUND_STATUS_SYNC_COPY'))
                    . '</p><button type="button" class="btn btn-secondary teya-refund-sync">'
                    . $escape(TeyaVirtuemartLanguage::text('REFUND_STATUS_SYNC')) . '</button>';
            }
            $panel .= '<p class="teya-refund-message" role="status" aria-live="polite"></p></div>';
            $html .= $this->getHtmlRowBE('VMPAYMENT_TEYA_REFUND', $panel);
            JFactory::getDocument()->addScript(rtrim(JUri::root(true), '/') . '/plugins/vmpayment/teya/assets/teya-refund.js?v=' . hash_file('sha256', __DIR__.'/assets/teya-refund.js'));
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    public function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    private function withConnectionLock($method, callable $callback)
    {
        $db = JFactory::getDBO();
        $id = (int) $method->virtuemart_paymentmethod_id;
        $lock = 'teya_vm_method_' . $id;
        $db->setQuery('SELECT GET_LOCK(' . $db->quote($lock) . ', 5)');
        if (!(int) $db->loadResult()) { throw new RuntimeException('Teya payment method is being updated.'); }
        try {
            $db->setQuery('SELECT payment_params FROM #__virtuemart_paymentmethods WHERE payment_element = '.$db->quote('teya').' AND virtuemart_paymentmethod_id = '.$id);
            $saved = $db->loadResult();
            if ($saved === null || $saved === false) { throw new RuntimeException('Teya payment method no longer exists.'); }
            $fresh = clone $method;
            foreach (TeyaVirtuemartCredentials::protectedKeys() as $key) { unset($fresh->$key); }
            foreach (explode('|', (string) $saved) as $part) {
                $pair = explode('=', $part, 2);
                if (count($pair) !== 2) { continue; }
                if (!in_array($pair[0], array_merge(TeyaVirtuemartCredentials::protectedKeys(), array('sandbox','debug')), true)) { continue; }
                $value = json_decode($pair[1], true);
                if (json_last_error() === JSON_ERROR_NONE && is_scalar($value)) { $fresh->{$pair[0]} = $value; }
            }
            return $callback($fresh);
        } finally {
            $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')'); $db->loadResult();
        }
    }

    private function assertNoPendingRefunds($method)
    {
        $db = JFactory::getDBO();
        $db->setQuery('SELECT COUNT(*) FROM '.$db->quoteName($this->_tablename)
            .' WHERE virtuemart_paymentmethod_id = '.(int) $method->virtuemart_paymentmethod_id
            ." AND teya_refund_status IN ('pending','blocked','status_pending')");
        if ((int) $db->loadResult() > 0) { throw new RuntimeException('Resolve pending refunds before changing the Teya connection.'); }
    }

    private function handleDisconnect($method, $environment)
    {
        $environment = TeyaVirtuemartUtils::normalizeEnvironment($environment);
        return $this->withConnectionLock($method, function ($method) use ($environment) {
            $this->assertNoPendingRefunds($method);
            TeyaVirtuemartOnboardingState::invalidate($method,$environment);
            if (!TeyaVirtuemartCredentials::isConnected($method, $environment)) {
                $this->savePaymentParams($method->virtuemart_paymentmethod_id, $method, $environment);
                return;
            }
            $methodId = (int) $method->virtuemart_paymentmethod_id;
            try {
                $this->apiClient($method)->deleteCurrentRegistration($this->getAccessToken($methodId, $method, $environment), $environment);
            } catch (TeyaVirtuemartApiException $exception) {
                if ($exception->getStatusCode() !== 401) {
                    throw $exception;
                }
                $this->clearAccessToken($methodId, $method, $environment);
                $this->apiClient($method)->deleteCurrentRegistration($this->getAccessToken($methodId, $method, $environment), $environment);
            }
            $prefix = $environment === TeyaVirtuemartUtils::ENV_TEST ? 'test_' : '';
            foreach (array('client_id', 'client_secret', 'payment_webhook_public_key', 'access_token', 'access_token_expires_at', 'access_token_scopes') as $key) {
                $method->{$prefix . $key} = '';
            }
            $this->savePaymentParams((int) $method->virtuemart_paymentmethod_id, $method, $environment);
        });
    }

    private function handleRegistrationNotification($method, $environment, $raw = null, $signature = null, $nonce = null)
    {
        $environment = TeyaVirtuemartUtils::normalizeEnvironment($environment);
        $raw = $raw ?? file_get_contents('php://input');
        $signature = $signature ?? $this->requestHeader('X-Teya-Signature');
        $client = $this->apiClient($method);
        $publicKey = $client->fetchRegistrationPublicKey($environment);
        TeyaVirtuemartWebhookVerifier::verify($raw, $signature, $publicKey);
        $payload = json_decode($raw, true);
        if (!is_array($payload) || empty($payload['client_id']) || empty($payload['client_secret']) || empty($payload['payment_webhook_public_key'])) {
            throw new RuntimeException('Invalid Teya registration payload.');
        }
        foreach (array('client_id','client_secret','payment_webhook_public_key') as $field) {
            if (!is_string($payload[$field]) || $payload[$field] === '') { throw new RuntimeException('Invalid registration field.'); }
        }
        $nonce = $nonce ?? JFactory::getApplication()->input->getString('nonce');
        return $this->withConnectionLock($method, function ($method) use ($environment,$nonce,$raw,$payload,$client) {
            if (!TeyaVirtuemartOnboardingState::validate($method,$environment,$nonce,$raw)) { return; }
            $this->assertNoPendingRefunds($method);
            $prefix = $environment === TeyaVirtuemartUtils::ENV_TEST ? 'test_' : '';
            $method->{$prefix . 'client_id'} = $payload['client_id'];
            $method->{$prefix . 'client_secret'} = $payload['client_secret'];
            $method->{$prefix . 'payment_webhook_public_key'} = $payload['payment_webhook_public_key'];
            $token = $client->requestAccessToken($payload['client_id'], $payload['client_secret'], $environment);
            $method->{$prefix . 'access_token'} = $token['access_token'];
            $method->{$prefix . 'access_token_scopes'} = '';
            $method->{$prefix . 'access_token_expires_at'} = time() + max(1, ((int) ($token['expires_in'] ?? 900)) - 60);
            if (!is_string($token['access_token'] ?? null) || $token['access_token'] === '') { throw new RuntimeException('Invalid OAuth response.'); }
            TeyaVirtuemartOnboardingState::complete($method,$environment,$raw);
            $this->savePaymentParams((int) $method->virtuemart_paymentmethod_id, $method, $environment);
            $this->writeLog('Teya ' . $environment . ' registration completed for VirtueMart payment method ' . $method->virtuemart_paymentmethod_id);
        });
    }

    private function handlePaymentNotification($method, $raw = null, $signature = null)
    {
        $raw = $raw ?? file_get_contents('php://input');
        $signature = $signature ?? $this->requestHeader('X-Teya-Signature');
        $payload = json_decode($raw, true);
        $sessionId = $payload['data']['session_id'] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('Invalid Teya webhook session.');
        }
        $payment = $this->findPaymentBySessionId($sessionId);
        if (!$payment || (int) $payment->virtuemart_paymentmethod_id !== (int) $method->virtuemart_paymentmethod_id
            || !in_array($payment->teya_environment, array('test', 'live'), true)) {
            throw new RuntimeException('Teya webhook does not match a payment.');
        }

        $stored = $this->decodeStoredResponse($payment);
        $publicKey = $stored['_webhook_public_key'] ?? '';
        if ($publicKey === '') {
            $publicKey = TeyaVirtuemartCredentials::value($method, 'payment_webhook_public_key', $payment->teya_environment);
        }
        TeyaVirtuemartWebhookVerifier::verify($raw, $signature, $publicKey);
        $this->applyPaymentNotification($method, $payload, $payment->teya_environment);
    }

    private function applyPaymentNotification($method, array $payload, $verifiedEnvironment)
    {
        $sessionId = $payload['data']['session_id'] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('Invalid Teya webhook session.');
        }
        $payment = $this->findPaymentBySessionId($sessionId);
        if (!$payment) {
            throw new RuntimeException('Teya payment not found.');
        }

        return $this->withPaymentLock((int) $payment->virtuemart_order_id, function () use ($method, $sessionId, $payload, $verifiedEnvironment) {
            $payment = $this->findPaymentBySessionId($sessionId);
            return $this->applyPaymentResult($method, $payment, $payload, $verifiedEnvironment, 'webhook');
        });
    }

    private function withPaymentLock($orderId, callable $callback)
    {
        $db = JFactory::getDBO();
        $lock = 'teya_vm_payment_' . (int) $orderId;
        $db->setQuery('SELECT GET_LOCK(' . $db->quote($lock) . ', 5)');
        if (!(int) $db->loadResult()) {
            throw new RuntimeException('Payment is being updated. Retry this request.');
        }
        try {
            return $callback();
        } finally {
            $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')');
            $db->loadResult();
        }
    }

    private function reconcileReturn($method, $orderId)
    {
        return $this->withConnectionLock($method, function ($method) use ($orderId) {
            return $this->withPaymentLock($orderId, function () use ($method, $orderId) {
                $payment = $this->latestPayment($orderId);
                if (!$payment || (int) $payment->virtuemart_paymentmethod_id !== (int) $method->virtuemart_paymentmethod_id) {
                    return 'pending';
                }

                $stored = $this->decodeStoredResponse($payment);
                $outcome = $this->webhookOutcome($stored);
                $sessionId = $payment->teya_session_id;
                $environment = $payment->teya_environment;
                if ($outcome === 'success' || !is_string($sessionId) || $sessionId === ''
                    || !in_array($environment, array('test', 'live'), true)
                    || (int) ($stored['_return_check_after'] ?? 0) > time()) {
                    return $outcome;
                }

                // Persist the cooldown before the call, including for unsuccessful requests.
                $stored['_return_check_after'] = time() + 15;
                $update = (object) array('id' => $payment->id, 'teya_response' => json_encode($stored, JSON_THROW_ON_ERROR));
                if (!JFactory::getDBO()->updateObject($this->_tablename, $update, 'id')) {
                    throw new RuntimeException('Could not save the session verification attempt.');
                }
                $payment->teya_response = $update->teya_response;

                $client = $this->apiClient($method);
                $methodId = (int) $method->virtuemart_paymentmethod_id;
                try {
                    $token = $this->getAccessToken($methodId, $method, $environment, true);
                    $response = $client->getCheckoutSession($sessionId, $token, $environment);
                } catch (TeyaVirtuemartApiException $exception) {
                    if ($exception->getStatusCode() !== 401) {
                        throw $exception;
                    }
                    $token = $this->getAccessToken($methodId, $method, $environment, true, true);
                    $response = $client->getCheckoutSession($sessionId, $token, $environment);
                }
                if (($response['_http_status'] ?? 0) !== 200 || ($response['session_id'] ?? '') !== $sessionId) {
                    throw new RuntimeException('Teya checkout session mismatch.');
                }
                if (($response['session_status'] ?? '') !== 'COMPLETED' || ($response['payment_status'] ?? '') !== 'SUCCESS') {
                    return $outcome;
                }
                $reference = $stored['_request']['merchant_reference'] ?? null;
                if (($response['type'] ?? '') !== 'SALE' || ($reference !== null
                    && ($response['merchant_reference'] ?? $response['source_reference_id'] ?? null) !== $reference)) {
                    throw new RuntimeException('Teya checkout payment reference or type mismatch.');
                }

                $payload = array('event' => 'payment.succeeded.v1', 'data' => array(
                    'session_id' => $sessionId, 'status' => 'SUCCESS',
                    'transaction_id' => $response['transaction_id'] ?? null,
                    'amount' => $response['amount'] ?? null,
                ));
                return $this->applyPaymentResult($method, $payment, $payload, $environment, 'session_lookup');
            });
        });
    }

    /** Apply verified payment evidence under the same order lock for either entry point. */
    private function applyPaymentResult($method, $payment, array $payload, $environment, $source)
    {
        $data = $payload['data'] ?? array();
        if (!$payment || (int) $payment->virtuemart_paymentmethod_id !== (int) $method->virtuemart_paymentmethod_id
            || $payment->teya_environment !== $environment || ($data['session_id'] ?? null) !== $payment->teya_session_id) {
            throw new RuntimeException('Teya payment method, environment or session mismatch.');
        }
        $outcome = $this->webhookOutcome($payload);
        if ($outcome === 'unsupported') {
            if ($source === 'session_lookup') {
                throw new RuntimeException('Teya session lookup did not return valid payment evidence.');
            }
            return 'unsupported';
        }
        $stored = $this->decodeStoredResponse($payment);
        $transactionId = $data['transaction_id'] ?? '';
        $amount = is_array($data['amount'] ?? null) ? $data['amount'] : array();
        $expected = $stored['_request']['amount'] ?? null;
        if ($outcome === 'success') {
            if (!$expected) {
                $order = VmModel::getModel('orders')->getOrder($payment->virtuemart_order_id);
                $currency = TeyaVirtuemartCheckoutBuilder::currencyCode($method, $order['details']['BT']);
                $expected = array('value' => TeyaVirtuemartUtils::toMinorUnits($payment->payment_order_total, $currency), 'currency' => $currency);
            }
            $value = $amount['value'] ?? null;
            if (!(is_int($value) || (is_string($value) && ctype_digit($value)))
                || (string) $value !== (string) $expected['value'] || ($amount['currency'] ?? null) !== $expected['currency']) {
                throw new RuntimeException('Teya payment amount or currency mismatch.');
            }
            if (!empty($payment->teya_transaction_id) && $this->webhookOutcome($stored) === 'success'
                && $payment->teya_transaction_id !== $transactionId) {
                throw new RuntimeException('Teya payment transaction mismatch.');
            }
        }
        $existing = $this->webhookOutcome($stored);
        if ($existing === 'success' || ($existing === 'failure' && $outcome === 'failure')) {
            return $existing;
        }

        $evidence = array(
            'event' => $payload['event'],
            'data' => array('session_id' => $payment->teya_session_id,
                'status' => $outcome === 'success' ? 'SUCCESS' : 'FAILED'),
            '_request' => $stored['_request'] ?? array('amount' => $expected),
            '_confirmation_source' => $source,
        );
        if ($outcome === 'success') {
            $evidence['data']['transaction_id'] = $transactionId;
            $evidence['data']['amount'] = array('value' => $amount['value'], 'currency' => $amount['currency']);
        }
        if (isset($stored['_webhook_public_key'])) {
            $evidence['_webhook_public_key'] = $stored['_webhook_public_key'];
        }

        $db = JFactory::getDBO();
        $db->transactionStart();
        try {
            $update = (object) array('id' => $payment->id,
                'teya_transaction_id' => $outcome === 'success' ? $transactionId : '',
                'teya_response' => json_encode($evidence, JSON_THROW_ON_ERROR));
            if (!$db->updateObject($this->_tablename, $update, 'id')) {
                throw new RuntimeException('Unable to persist Teya payment confirmation.');
            }
            $history = array(
                'items' => array(),
                'order_status' => $outcome === 'success' ? $method->status_success : $method->status_failed,
                'customer_notified' => $outcome === 'success',
                'comments' => 'Teya payment ' . $outcome . ' (' . $source . ').',
            );
            if (!$this->updateNativeOrderStatus($payment->virtuemart_order_id, $history)) {
                throw new RuntimeException('Unable to update the order status.');
            }
            $db->transactionCommit();
        } catch (Throwable $exception) {
            $db->transactionRollback();
            throw $exception;
        }
        return $outcome;
    }

    private function buildCheckoutUrls(array $order)
    {
        $methodId = (int) $order['details']['BT']->virtuemart_paymentmethod_id;
        $orderNumber = urlencode($order['details']['BT']->order_number);
        $itemId = vRequest::getInt('Itemid');
        $lang = vRequest::getCmd('lang', '');
        $base = rtrim(JUri::root(), '/') . '/index.php?option=com_virtuemart&view=pluginresponse';
        return array(
            'success_url' => $base . '&task=pluginresponsereceived&pm=' . $methodId . '&on=' . $orderNumber . '&Itemid=' . $itemId . '&lang=' . $lang,
            'failure_url' => $base . '&task=pluginUserPaymentCancel&pm=' . $methodId . '&on=' . $orderNumber . '&Itemid=' . $itemId . '&lang=' . $lang,
            'cancel_url' => $base . '&task=pluginUserPaymentCancel&pm=' . $methodId . '&on=' . $orderNumber . '&Itemid=' . $itemId . '&lang=' . $lang,
            'notification_url' => $base . '&task=pluginnotification&tmpl=component&pm=' . $methodId . '&on=' . $orderNumber . '&teya_action=payment&lang=' . $lang,
        );
    }

    public static function disconnectUrl($methodId, $environment)
    {
        $environment = TeyaVirtuemartUtils::normalizeEnvironment($environment);
        $lang = JFactory::getApplication()->input->getCmd('lang', '');
        return rtrim(JUri::root(), '/') . '/administrator/index.php?option=com_virtuemart&teya_admin=1&pm=' . (int) $methodId . '&teya_action=disconnect&environment=' . $environment . '&lang=' . $lang;
    }

    public static function statusUrl($methodId, $environment)
    {
        $environment = TeyaVirtuemartUtils::normalizeEnvironment($environment);
        $lang = JFactory::getApplication()->input->getCmd('lang', '');
        return rtrim(JUri::root(), '/') . '/administrator/index.php?option=com_virtuemart&teya_admin=1&pm=' . (int) $methodId . '&teya_action=status&environment=' . $environment . '&lang=' . $lang;
    }

    public static function registrationUrl($methodId, $environment, $nonce)
    {
        $environment = TeyaVirtuemartUtils::normalizeEnvironment($environment);
        $lang = JFactory::getApplication()->input->getCmd('lang', '');
        $callback = rtrim(JUri::root(), '/') . '/index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&pm=' . (int) $methodId . '&teya_action=registration&environment=' . $environment . '&nonce=' . rawurlencode($nonce) . '&lang=' . $lang;
        $paymentWebhook = rtrim(JUri::root(), '/') . '/index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component&pm=' . (int) $methodId . '&teya_action=payment&lang=' . $lang;
        return TeyaVirtuemartUtils::businessRegistrationUrl($environment) . '?' . http_build_query(array(
            'registrationWebhook' => $callback,
            'paymentsWebhook' => $paymentWebhook,
            'pluginType' => 'VIRTUEMART_WEBENSOL',
            'pluginVersion' => TeyaVirtuemartUtils::VERSION,
            'returnUrl' => rtrim(JUri::root(), '/') . '/administrator/index.php?option=com_virtuemart&view=paymentmethod&task=edit&cid[]=' . (int) $methodId,
        ));
    }

    private function getAccessToken($methodId, $method, $environment, $sessionRead = false, $force = false)
    {
        $environment = TeyaVirtuemartUtils::normalizeEnvironment($environment);
        $prefix = $environment === TeyaVirtuemartUtils::ENV_TEST ? 'test_' : '';
        $tokenKey = $prefix . 'access_token';
        $expiresKey = $prefix . 'access_token_expires_at';
        $scopesKey = $prefix . 'access_token_scopes';
        if (!$force && !empty($method->$tokenKey) && !empty($method->$expiresKey) && (int) $method->$expiresKey > time()
            && (!$sessionRead || ($method->$scopesKey ?? '') === TeyaVirtuemartApiClient::SESSION_READ_SCOPE)) {
            return TeyaVirtuemartCredentials::value($method, 'access_token', $environment);
        }
        $clientId = TeyaVirtuemartCredentials::value($method, 'client_id', $environment);
        $clientSecret = TeyaVirtuemartCredentials::value($method, 'client_secret', $environment);
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Teya client credentials are not configured.');
        }
        $token = $this->apiClient($method)->requestAccessToken($clientId, $clientSecret, $environment, $sessionRead);
        if (!is_string($token['access_token'] ?? null) || $token['access_token'] === '') { throw new RuntimeException('Invalid OAuth response.'); }
        $method->$tokenKey = $token['access_token'];
        $method->$scopesKey = $sessionRead ? TeyaVirtuemartApiClient::SESSION_READ_SCOPE : '';
        $method->$expiresKey = time() + max(1, ((int) ($token['expires_in'] ?? 900)) - 60);
        $this->savePaymentParams($methodId, $method, $environment, false, true);
        return $token['access_token'];
    }


    private function connectionStatus($method, $environment)
    {
        $environment = TeyaVirtuemartUtils::normalizeEnvironment($environment);
        return array(
            'connected' => TeyaVirtuemartCredentials::isConnected($method, $environment),
            'completed_attempt' => isset(TeyaVirtuemartOnboardingState::intents($method)[$environment]['completed']) ? (TeyaVirtuemartOnboardingState::intents($method)[$environment]['hash'] ?? '') : '',
            'connected_method_id' => 0,
            'environment' => $environment,
            'active_environment' => TeyaVirtuemartUtils::environmentFromParams($method),
            'csrf_token' => $this->requestTokenName(),
        );
    }

    private function disconnectPaymentMethodRegistrations($method)
    {
        foreach (array(TeyaVirtuemartUtils::ENV_LIVE, TeyaVirtuemartUtils::ENV_TEST) as $environment) {
            if (TeyaVirtuemartCredentials::isConnected($method, $environment)) {
                $this->handleDisconnect($method, $environment);
            }
        }
    }

    private function createOrResumeCheckout(array $order, $method, $environment, array $payload)
    {
        return $this->withConnectionLock($method, function ($method) use ($order,$environment,$payload) {
            return $this->createOrResumeCheckoutLocked($order,$method,$environment,$payload);
        });
    }

    private function createOrResumeCheckoutLocked(array $order, $method, $environment, array $payload)
    {
        $details = $order['details']['BT'];
        $db = JFactory::getDBO();
        $lock = 'teya_vm_payment_' . (int) $details->virtuemart_order_id;
        $db->setQuery('SELECT GET_LOCK(' . $db->quote($lock) . ', 0)');
        if (!(int) $db->loadResult()) {
            throw new RuntimeException('A payment request is already being processed. Please try again shortly.');
        }
        try {
            $db->setQuery('SELECT * FROM ' . $db->quoteName($this->_tablename)
                . ' WHERE virtuemart_order_id = ' . (int) $details->virtuemart_order_id . ' ORDER BY id DESC', 0, 1);
            $payment = $db->loadObject();
            $stored = $payment ? $this->decodeStoredResponse($payment) : array();
            if ($payment && $this->webhookOutcome($stored) === 'success') {
                throw new RuntimeException('This order has already been paid.');
            }
            $sameMethod = $payment && (int) $payment->virtuemart_paymentmethod_id === (int) $method->virtuemart_paymentmethod_id
                && $payment->teya_environment === $environment;
            $sameAmount = isset($stored['_request']['amount']) && $stored['_request']['amount'] === $payload['amount'];
            $unresolved = $payment && $this->webhookOutcome($stored) !== 'failure'
                && (!empty($stored['_checkout_key']) || !empty($payment->teya_session_id));
            if ($unresolved && (!$sameMethod || !$sameAmount)) {
                throw new RuntimeException('An earlier checkout is unresolved. Reconcile it before changing the payment method, environment or amount.');
            }
            // Elapsed wall time is not proof that an earlier payment can no longer succeed.
            // Reuse its session until a signed terminal notification establishes failure/expiry.
            if ($unresolved && !empty($stored['session_url']) && !empty($stored['session_id'])) {
                return $stored;
            }
            $resume = $sameMethod && !empty($stored['_checkout_key']) && empty($stored['session_id']);
            if ($resume && !$sameAmount) {
                throw new RuntimeException('The order amount changed while a Teya request was unresolved. Reconcile that request before retrying payment.');
            }
            if ($resume) {
                $state = $stored;
            } else {
                $state = array('_checkout_key' => TeyaVirtuemartUtils::createIdempotencyKey(), '_created_at' => time(), '_request' => $payload, '_webhook_public_key'=>TeyaVirtuemartCredentials::value($method,'payment_webhook_public_key',$environment));
                $values = array('virtuemart_order_id' => $details->virtuemart_order_id,
                    'order_number' => $details->order_number, 'virtuemart_paymentmethod_id' => $method->virtuemart_paymentmethod_id,
                    'payment_name' => $this->renderPluginName($method), 'payment_order_total' => $details->order_total,
                    'payment_currency' => $details->order_currency, 'email_currency' => $this->getEmailCurrency($method),
                    'teya_environment' => $environment, 'teya_response' => json_encode($state, JSON_THROW_ON_ERROR));
                $this->storePSPluginInternalData($values);
                $db->setQuery('SELECT * FROM ' . $db->quoteName($this->_tablename)
                    . ' WHERE virtuemart_order_id = ' . (int) $details->virtuemart_order_id . ' ORDER BY id DESC', 0, 1);
                $payment = $db->loadObject();
                if (!$payment || $this->decodeStoredResponse($payment) !== json_decode(json_encode($state, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)) {
                    throw new RuntimeException('Could not persist the checkout request.');
                }
            }
            $response = $this->createCheckoutSessionWithTokenRetry((int) $method->virtuemart_paymentmethod_id,
                $method, $environment, $state['_request'], $state['_checkout_key']);
            if (in_array($response['code'] ?? '', array('LINE_ITEMS_TOTAL_MISMATCH', 'INVALID_LINE_ITEMS'), true)
                && in_array($response['_http_status'] ?? null, array(400, 422), true)
                && count($state['_request']['line_items'] ?? array()) > 1) {
                // A definitive rejection permits a new key with the corrected basket.
                $state['_request']['line_items'] = array(array('description' => 'Order ' . $details->order_number,
                    'quantity' => 1, 'unit_price' => $state['_request']['amount']['value']));
                $state['_checkout_key'] = TeyaVirtuemartUtils::createIdempotencyKey();
                $update = (object) array('id'=>$payment->id,'teya_response'=>json_encode($state,JSON_THROW_ON_ERROR));
                if (!$db->updateObject($this->_tablename,$update,'id')) { throw new RuntimeException('Could not save the checkout retry.'); }
                $response = $this->createCheckoutSessionWithTokenRetry((int) $method->virtuemart_paymentmethod_id,
                    $method, $environment, $state['_request'], $state['_checkout_key']);
            }
            if (!is_string($response['session_id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $response['session_id'])
                || !is_string($response['session_url'] ?? null) || !in_array($response['_http_status'] ?? 201, array(200, 201), true)
                || !filter_var($response['session_url'], FILTER_VALIDATE_URL)
                || strtolower((string) parse_url($response['session_url'], PHP_URL_SCHEME)) !== 'https'
                || parse_url($response['session_url'], PHP_URL_USER) !== null) {
                $httpStatus = (int) ($response['_http_status'] ?? 0);
                if ($httpStatus >= 400 && $httpStatus < 500 && !in_array($httpStatus, array(401, 403, 408, 409, 429), true)) {
                    unset($state['_checkout_key']);
                    $state['_rejection'] = TeyaVirtuemartApiClient::safeApiError($response)['code'];
                    $update = (object) array('id'=>$payment->id,'teya_response'=>json_encode($state,JSON_THROW_ON_ERROR));
                    if (!$db->updateObject($this->_tablename,$update,'id')) { throw new RuntimeException('Could not save the checkout rejection.'); }
                }
                throw new RuntimeException('Teya did not return a valid checkout session.');
            }
            $state['session_id'] = $response['session_id'];
            $state['session_url'] = $response['session_url'];
            $update = (object) array('id'=>$payment->id,'teya_session_id'=>$response['session_id'],'teya_response'=>json_encode($state,JSON_THROW_ON_ERROR));
            if (!$db->updateObject($this->_tablename,$update,'id')) { throw new RuntimeException('Could not save the checkout session.'); }
            $history = array('items'=>array(), 'order_status' => $method->status_pending ?: 'U', 'customer_notified' => false,
                'comments' => 'Teya checkout session: ' . $response['session_id']);
            if (!$this->updateNativeOrderStatus($details->virtuemart_order_id,$history)) {
                throw new RuntimeException('Could not save the pending order status.');
            }
            return $state;
        } finally {
            $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')');
            $db->loadResult();
        }
    }

    private function createCheckoutSessionWithTokenRetry($methodId, $method, $environment, array $payload, $key = null)
    {
        $client = $this->apiClient($method);
        $key = $key ?: TeyaVirtuemartUtils::createIdempotencyKey();
        try {
            return $client->createCheckoutSession($payload, $this->getAccessToken($methodId, $method, $environment), $environment, $key);
        } catch (TeyaVirtuemartApiException $exception) {
            if ($exception->getStatusCode() !== 401) {
                throw $exception;
            }
            $this->clearAccessToken($methodId, $method, $environment);
            return $client->createCheckoutSession($payload, $this->getAccessToken($methodId, $method, $environment), $environment, $key);
        }
    }

    private function clearAccessToken($methodId, $method, $environment)
    {
        $prefix = TeyaVirtuemartUtils::normalizeEnvironment($environment) === TeyaVirtuemartUtils::ENV_TEST ? 'test_' : '';
        $method->{$prefix . 'access_token'} = '';
        $method->{$prefix . 'access_token_scopes'} = '';
        $method->{$prefix . 'access_token_expires_at'} = '';
        $this->savePaymentParams($methodId, $method, $environment, false, true);
    }

    private function webhookOutcome(array $payload)
    {
        $event = is_string($payload['event'] ?? null) ? strtolower($payload['event']) : '';
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
        $status = $data['status'] ?? $data['payment_status'] ?? '';
        if (!is_string($status)) {
            return 'unsupported';
        }
        $status = strtoupper($status);
        $transactionId = $data['transaction_id'] ?? null;
        if ($event === 'payment.succeeded.v1' && $status === 'SUCCESS' && is_string($transactionId)
            && preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $transactionId)) {
            return 'success';
        }
        if (in_array($event, array('payment.failed.v1', 'payment.cancelled.v1', 'payment.canceled.v1', 'payment.expired.v1'), true)
            && in_array($status, array('', 'FAILURE', 'FAILED', 'CANCELED', 'CANCELLED', 'EXPIRED'), true)) {
            return 'failure';
        }
        return 'unsupported';
    }

    private function decodeStoredResponse($payment)
    {
        $decoded = !empty($payment->teya_response) ? json_decode((string) $payment->teya_response, true) : array();
        return is_array($decoded) ? $decoded : array();
    }

    private function savePaymentParams($methodId, $method, $environment, $activate = false, $tokensOnly = false)
    {
        $db = JFactory::getDBO();
        $db->setQuery('SELECT payment_params FROM #__virtuemart_paymentmethods WHERE virtuemart_paymentmethod_id = ' . (int) $methodId);
        $parts = array();
        $previousParams = (string) $db->loadResult();
        foreach (explode('|', $previousParams) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $part;
            }
        }
        $prefix = $environment === TeyaVirtuemartUtils::ENV_TEST ? 'test_' : '';
        $names = $tokensOnly ? array('access_token', 'access_token_expires_at', 'access_token_scopes')
            : array('client_id', 'client_secret', 'payment_webhook_public_key', 'access_token', 'access_token_expires_at', 'access_token_scopes');
        foreach ($names as $name) {
            $key = $prefix . $name;
            if (isset($method->$key)) {
                $parts[$key] = $key . '=' . str_replace('|', '\u007c', json_encode((string) TeyaVirtuemartCredentials::protect($method)->$key, JSON_THROW_ON_ERROR));
            }
        }
        if (isset($method->teya_onboarding)) {
            $parts['teya_onboarding'] = 'teya_onboarding=' . json_encode((string) $method->teya_onboarding, JSON_THROW_ON_ERROR);
        }
        if ($activate) {
            $parts['sandbox'] = 'sandbox=' . json_encode((int) $method->sandbox);
        }
        $updatedParams = implode('|', $parts);
        if ($updatedParams === $previousParams) {
            return;
        }
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__virtuemart_paymentmethods'))
            ->set($db->quoteName('payment_params') . ' = ' . $db->quote($updatedParams))
            ->where($db->quoteName('virtuemart_paymentmethod_id') . ' = ' . (int) $methodId)
            ->where($db->quoteName('payment_params') . ' = ' . $db->quote($previousParams))
            ->where($db->quoteName('payment_element') . ' = ' . $db->quote('teya'));
        $db->setQuery($query);
        $db->execute();
        if ($db->getAffectedRows() !== 1) {
            throw new RuntimeException('The Teya connection changed during this request. Refresh and try again.');
        }
    }

    private function canManage($permission)
    {
        $app = JFactory::getApplication();
        $user = JFactory::getUser();
        return $app->isClient('administrator') && !$user->guest
            && ($user->authorise('core.admin', 'com_virtuemart') || $user->authorise($permission, 'com_virtuemart'));
    }

    private function createRefundWithTokenRetry($methodId, $method, $environment, array $attempt)
    {
        $client = $this->apiClient($method);
        try {
            return $client->createRefund($attempt['payload'], $this->getAccessToken($methodId, $method, $environment), $environment, $attempt['key']);
        } catch (TeyaVirtuemartApiException $exception) {
            if ($exception->getStatusCode() !== 401) {
                throw $exception;
            }
            $this->clearAccessToken($methodId, $method, $environment);
            return $client->createRefund($attempt['payload'], $this->getAccessToken($methodId, $method, $environment), $environment, $attempt['key']);
        }
    }

    private function latestPayment($orderId)
    {
        $db=JFactory::getDBO();
        $db->setQuery('SELECT * FROM '.$db->quoteName($this->_tablename).' WHERE virtuemart_order_id='.(int)$orderId.' ORDER BY id DESC',0,1);
        return $db->loadObject();
    }

    private function refundBalance($payment)
    {
        $response = $this->decodeStoredResponse($payment);
        if ($this->webhookOutcome($response) !== 'success' || empty($payment->teya_transaction_id)
            || ($response['data']['transaction_id'] ?? '') !== $payment->teya_transaction_id
            || empty($response['data']['amount']['currency']) || empty($response['data']['amount']['value'])) {
            throw new RuntimeException('A verified successful Teya payment is required for a refund.');
        }
        return $response['data']['amount'];
    }

    private function saveRefundState($payment, array $state, $status, array $attempt)
    {
        $values = (object) array('id' => $payment->id,
            'teya_refund_status' => $status, 'teya_refund_key' => $attempt['key'],
            'teya_refund_amount' => $attempt['payload']['amount'],
            'teya_refund_currency' => $this->refundBalance($payment)['currency'],
            'teya_refund_response' => json_encode($state, JSON_THROW_ON_ERROR));
        if (!JFactory::getDBO()->updateObject($this->_tablename, $values, 'id')) {
            throw new RuntimeException('Could not save the refund state.');
        }
    }

    private function processRefund($orderId, $method, $inputAmount = null, $requestKey = null, $retryOnly = false, $resumeBlocked = false)
    {
        return $this->withConnectionLock($method, function ($method) use ($orderId,$inputAmount,$requestKey,$retryOnly,$resumeBlocked) {
            return $this->processRefundLocked($orderId,$method,$inputAmount,$requestKey,$retryOnly,$resumeBlocked);
        });
    }

    private function processRefundLocked($orderId, $method, $inputAmount, $requestKey, $retryOnly, $resumeBlocked)
    {
        $db = JFactory::getDBO();
        $lock = 'teya_vm_refund_' . (int) $orderId;
        $db->setQuery('SELECT GET_LOCK(' . $db->quote($lock) . ', 0)');
        if (!(int) $db->loadResult()) {
            throw new RuntimeException('This payment is being updated. Try again shortly.');
        }
        try {
            // Bypass VirtueMart static cache and read only while holding the lock.
            $db->setQuery('SELECT * FROM ' . $db->quoteName($this->_tablename)
                . ' WHERE virtuemart_order_id = ' . (int) $orderId . ' ORDER BY id DESC', 0, 1);
            $payment = $db->loadObject();
            if (!$payment || (int) $payment->virtuemart_paymentmethod_id !== (int) $method->virtuemart_paymentmethod_id) {
                throw new RuntimeException('Teya payment not found for this order.');
            }
            $paid = $this->refundBalance($payment);
            $state = TeyaVirtuemartRefundState::load($payment);
            $remaining = (int) $paid['value'] - TeyaVirtuemartRefundState::refunded($state);
            $alreadyProcessed = $requestKey && in_array($requestKey, array_column($state['refunds'], 'key'), true);
            if ($alreadyProcessed || ($retryOnly && empty($state['pending'])) || $remaining <= 0) {
                return array('pending' => false, 'remaining' => $remaining, 'currency' => $paid['currency']);
            }
            if (!empty($state['pending']) && !$retryOnly && $requestKey && $requestKey !== $state['pending']['key']) {
                throw new RuntimeException('A refund is unresolved. Retry the existing refund reference.');
            }
            if (empty($state['pending'])) {
                $amount = $inputAmount === null ? $remaining : TeyaVirtuemartRefundState::amount($inputAmount, $paid['currency'], $remaining);
                $state['pending'] = array('key' => $requestKey ?: TeyaVirtuemartUtils::createIdempotencyKey(),
                    'next_attempt' => 0, 'tries' => 0,
                    'payload' => array('transaction_id' => (string) $payment->teya_transaction_id,
                        'amount' => $amount, 'source_reference_id' => substr('VirtueMartOrder' . $orderId, 0, 30),
                        'reason' => 'VirtueMart refund'));
            }
            $attempt = $state['pending'];
            if (!empty($attempt['blocked']) && !$resumeBlocked) {
                return array('pending'=>true, 'blocked'=>true, 'remaining'=>$remaining, 'currency'=>$paid['currency']);
            }
            if (!empty($attempt['blocked'])) { unset($attempt['blocked']); $attempt['next_attempt']=0; }
            if (($attempt['next_attempt'] ?? 0) > time()) {
                return array('pending' => true, 'retry_at'=>(int) $attempt['next_attempt'], 'remaining' => $remaining, 'currency' => $paid['currency']);
            }
            $attempt['tries'] = ($attempt['tries'] ?? 0) + 1;
            $attempt['next_attempt'] = time() + min(300, 5 * (2 ** min(6, $attempt['tries'] - 1)));
            $state['pending'] = $attempt;
            // Persist before the API call: a timeout does not prove failure.
            $this->saveRefundState($payment, $state, 'pending', $attempt);
            try {
                $response = $this->createRefundWithTokenRetry((int) $method->virtuemart_paymentmethod_id,
                    $method, $payment->teya_environment, $attempt);
            } catch (TeyaVirtuemartApiException $exception) {
                $code = $exception->getStatusCode();
                $body = $exception->getResponseBody();
                if (in_array($code, array(401,403), true)) {
                    $state['pending']['blocked'] = true;
                    $state['pending']['next_attempt'] = 0;
                    $this->saveRefundState($payment, $state, 'blocked', $attempt);
                    return array('pending'=>true, 'blocked'=>true, 'remaining'=>$remaining, 'currency'=>$paid['currency']);
                }
                $delay = max(0, (int) ($body['_retry_after'] ?? 0));
                if ($delay) { $state['pending']['next_attempt'] = max($attempt['next_attempt'], time()+$delay); }
                if ($code >= 400 && $code < 500 && !in_array($code, array(401, 403, 408, 409, 429), true)
                    && ($body['code'] ?? '') !== 'DUPLICATE_REFUND') {
                    unset($state['pending']);
                    $state['last_error'] = 'Teya rejected the refund request (HTTP '.(int) $code.').';
                    $this->saveRefundState($payment, $state, 'failure', $attempt);
                    throw new RuntimeException($state['last_error']);
                }
                $response = array('_http_status' => $code);
            } catch (RuntimeException $exception) {
                $response = array('status' => 'UNKNOWN');
            }
            unset($state['last_response']);
            if (!empty($response['_retry_after'])) {
                $state['pending']['next_attempt'] = max($state['pending']['next_attempt'], time()+(int) $response['_retry_after']);
            }
            $status = 'pending';
            if (($response['_http_status'] ?? 0) === 201 && ($response['status'] ?? '') === 'SUCCESS') {
                if (isset($response['refund_amount']) &&
                    (($response['refund_amount']['currency'] ?? '') !== $paid['currency']
                    || (string) ($response['refund_amount']['amount'] ?? '') !== (string) $attempt['payload']['amount'])) {
                    throw new RuntimeException('Teya returned a different refund amount. Reconciliation is required.');
                }
                $state['refunds'][] = array('key' => $attempt['key'], 'amount' => $attempt['payload']['amount'],
                    'status' => 'SUCCESS', 'transaction_id'=>(is_string($response['transaction_id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$response['transaction_id']) ? $response['transaction_id'] : null), 'confirmed_at' => gmdate('c'));
                unset($state['pending']);
                $status = 'success';
                $remaining -= $attempt['payload']['amount'];
            } elseif (($response['_http_status'] ?? 0) === 201 && ($response['status'] ?? '') === 'FAILURE') {
                unset($state['pending']);
                $status = 'failure';
            }
            $recordStatus = $status === 'success' && $remaining === 0 ? 'status_pending' : $status;
            $this->saveRefundState($payment, $state, $recordStatus, $attempt);
            if ($status === 'failure') {
                throw new RuntimeException('Teya declined the refund. Check the order payment details.');
            }
            return array('pending' => $status === 'pending', 'remaining' => $remaining, 'currency' => $paid['currency'], 'retry_at'=>(int) ($state['pending']['next_attempt'] ?? 0));
        } finally {
            $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')');
            $db->loadResult();
        }
    }

    public function resumePendingRefunds($limit = 10)
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Refund recovery is only available from the command line.');
        }
        $db = JFactory::getDBO();
        $db->setQuery('SELECT virtuemart_order_id, virtuemart_paymentmethod_id FROM ' . $db->quoteName($this->_tablename)
            . " WHERE teya_refund_status IN ('pending','status_pending') ORDER BY id ASC", 0, max(1, min(50, (int) $limit)));
        $results = array();
        foreach ($db->loadObjectList() as $row) {
            try {
                $method = $this->getVmPluginMethod($row->virtuemart_paymentmethod_id, false);
                if (!$method || !$this->selectedThisElement($method->payment_element)) {
                    throw new RuntimeException('Original Teya payment method no longer exists.');
                }
                $this->_currentMethod = $method;
                $result = $this->processRefund((int) $row->virtuemart_order_id, $method, null, null, true);
                if (!$result['pending'] && $result['remaining'] === 0) {
                    $this->markOrderRefunded((int) $row->virtuemart_order_id, $method);
                }
                $results[$row->virtuemart_order_id] = $result['pending'] ? 'pending' : 'confirmed';
            } catch (Exception $exception) {
                $results[$row->virtuemart_order_id] = 'error: refund recovery failed; review the saved order state';
            }
        }
        return $results;
    }

    private function updateNativeOrderStatus($orderId, array $history)
    {
        // The early admin AJAX route and CLI bypass Joomla's component dispatcher.
        // VM invoice/email controllers still require the normal component constants.
        if (defined('JPATH_SITE') && !defined('JPATH_COMPONENT_SITE')) { define('JPATH_COMPONENT_SITE',JPATH_SITE.'/components/com_virtuemart'); }
        if (defined('JPATH_ADMINISTRATOR') && !defined('JPATH_COMPONENT_ADMINISTRATOR')) { define('JPATH_COMPONENT_ADMINISTRATOR',JPATH_ADMINISTRATOR.'/components/com_virtuemart'); }
        if (defined('JPATH_COMPONENT_SITE') && !defined('JPATH_COMPONENT')) { define('JPATH_COMPONENT',JPATH_COMPONENT_SITE); }
        return VmModel::getModel('orders')->updateStatusForOneOrder($orderId,$history,false);
    }

    private function markOrderRefunded($orderId, $method)
    {
        $order = VmModel::getModel('orders')->getOrder($orderId);
        if (($order['details']['BT']->order_status ?? '') !== $method->status_refund) {
            $history = array('items'=>array(), 'order_status' => $method->status_refund, 'customer_notified' => true,
                'comments' => 'Teya confirmed the full refund. See payment details for refund transaction references.');
            if (!$this->updateNativeOrderStatus($orderId,$history)) {
                throw new RuntimeException('Refund confirmed by Teya, but the order status could not be saved. Refresh the order and retry the status update.');
            }
        }
        $db=JFactory::getDBO();
        $db->setQuery('UPDATE '.$db->quoteName($this->_tablename)." SET teya_refund_status='success' WHERE virtuemart_order_id=".(int)$orderId." AND teya_refund_status='status_pending'")->execute();
    }

    private function handleAdminRefund($method, $retryOnly)
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->checkRequestToken('post')) {
            $this->respond(403, 'Invalid session token');
        }
        $input = JFactory::getApplication()->input;
        $orderId = $input->getInt('order_id');
        $key = $input->getString('refund_key');
        if (!$retryOnly && !preg_match('/^[a-f0-9-]{36}$/D', $key)) {
            throw new RuntimeException('Invalid refund request reference.');
        }
        $result = $this->processRefund($orderId, $method, $input->getString('amount'), $key, $retryOnly, $retryOnly);
        if (!$result['pending'] && $result['remaining'] === 0) {
            $this->markOrderRefunded($orderId, $method);
        }
        $this->respondJson(200, $result);
    }

    private function findPaymentBySessionId($sessionId)
    {
        $db = JFactory::getDBO();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName($this->_tablename))
            ->where($db->quoteName('teya_session_id') . ' = ' . $db->quote($sessionId))
            ->order($db->quoteName('id') . ' DESC');
        $db->setQuery($query, 0, 1);
        return $db->loadObject();
    }

    protected function apiClient($method)
    {
        return new TeyaVirtuemartApiClient(!empty($method->debug), array($this, 'writeLog'));
    }

    public function writeLog($message)
    {
        if (!empty($this->_currentMethod->debug)) {
            $this->logInfo('Teya: ' . $message, 'debug', true);
        }
    }

    private function loadTeyaLanguage()
    {
        if (class_exists('vmLanguage')) {
            vmLanguage::loadJLang('plg_vmpayment_teya', true);
        }
        $language = JFactory::getLanguage();
        $language->load('plg_vmpayment_teya', __DIR__, null, true, true);
        $language->load('plg_vmpayment_teya', __DIR__ . '/language', null, true, true);
        $language->load('plg_vmpayment_teya', JPATH_SITE, null, true, true);
        $language->load('plg_vmpayment_teya', JPATH_ADMINISTRATOR, null, true, true);
    }

    private function requestHeader($name)
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$serverKey]) ? (string) $_SERVER[$serverKey] : '';
    }


    private function requestTokenName()
    {
        if (class_exists('\Joomla\CMS\Session\Session')) {
            return \Joomla\CMS\Session\Session::getFormToken();
        }
        return class_exists('JSession') ? JSession::getFormToken() : '';
    }

    private function checkRequestToken($method)
    {
        if (class_exists('\Joomla\CMS\Session\Session')) {
            return \Joomla\CMS\Session\Session::checkToken($method);
        }
        return class_exists('JSession') ? JSession::checkToken($method) : false;
    }

    private function respondJson($status, array $payload)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload);
        exit;
    }

    private function respond($status, $message)
    {
        http_response_code((int) $status);
        echo $message;
        exit;
    }
}

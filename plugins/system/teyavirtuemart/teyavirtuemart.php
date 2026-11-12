<?php
/**
 * @package    Teya Payments for VirtueMart
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or die('Restricted access');

// The companion may remain enabled after the payment plugin is uninstalled.
if (!is_file(JPATH_SITE . '/plugins/vmpayment/teya/src/TeyaVirtuemartUtils.php')) {
    return;
}
require_once JPATH_SITE . '/plugins/vmpayment/teya/src/TeyaVirtuemartCompatibility.php';
require_once JPATH_SITE . '/plugins/vmpayment/teya/src/TeyaVirtuemartUtils.php';
require_once JPATH_SITE . '/plugins/vmpayment/teya/src/TeyaVirtuemartCredentials.php';
require_once JPATH_SITE . '/plugins/vmpayment/teya/src/TeyaVirtuemartApiClient.php';

if (!class_exists('JPlugin') && class_exists('\Joomla\CMS\Plugin\CMSPlugin')) {
    class_alias('\Joomla\CMS\Plugin\CMSPlugin', 'JPlugin');
}

class plgSystemTeyaVirtuemart extends JPlugin
{
    public function onAfterInitialise()
    {
        $app = JFactory::getApplication();
        if (method_exists($app, 'isClient') && !$app->isClient('administrator')) {
            return;
        }
        if (method_exists($app, 'isAdmin') && !$app->isAdmin()) {
            return;
        }

        $input = $app->input;
        if ($input->getCmd('option') !== 'com_virtuemart') {
            return;
        }
        if ($input->getInt('teya_admin') === 1) {
            $user = JFactory::getUser();
            if ($user->guest || !$user->authorise('core.manage', 'com_virtuemart')) {
                throw new RuntimeException('Administrator permission required', 403);
            }
            require_once JPATH_ADMINISTRATOR . '/components/com_virtuemart/helpers/config.php';
            VmConfig::loadConfig();
            vDispatcher::importVMPlugins('vmpayment');
            vDispatcher::directTrigger('vmpayment', 'teya', 'plgVmOnPaymentNotification', array());
            return;
        }
        if ($input->getCmd('view') !== 'paymentmethod') {
            return;
        }
        // Secrets and callback state are server-owned, never accepted from form fields.
        if (in_array($input->getCmd('task'), array('save', 'apply', 'save2new'), true)) {
            $user = JFactory::getUser();
            if ($user->guest || !($user->authorise('core.admin', 'com_virtuemart') || $user->authorise('vm.paymentmethod.edit', 'com_virtuemart'))
                || !\Joomla\CMS\Session\Session::checkToken('post')) {
                throw new RuntimeException('Not authorised to save a Teya payment method', 403);
            }
            $id = $input->getInt('virtuemart_paymentmethod_id');
            if ($id > 0) {
                $db = JFactory::getDBO();
                $lock = 'teya_vm_method_' . (int) $id;
                $db->setQuery('SELECT GET_LOCK(' . $db->quote($lock) . ', 5)');
                if (!(int) $db->loadResult()) { throw new RuntimeException('Teya payment method is being updated.'); }
                register_shutdown_function(function () use ($db,$lock) {
                    $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')'); $db->loadResult();
                });
            }
            $method = $this->loadTeyaPaymentMethod($id);
            if ($method) {
                $posted = $input->post->get('params', null, 'array');
                if (!is_array($posted)) {
                    throw new RuntimeException('Payment settings must be submitted as an array.', 400);
                }
                $safe = TeyaVirtuemartCredentials::protect($method);
                foreach (TeyaVirtuemartCredentials::protectedKeys() as $key) {
                    $posted[$key] = $safe->$key ?? '';
                }
                $input->post->set('params', $posted);
                if (!class_exists('vRequest')) {
                    require_once JPATH_ADMINISTRATOR . '/components/com_virtuemart/helpers/vrequest.php';
                }
                // setVar() also invalidates cached filtered values used by the native controller.
                vRequest::setVar('params', $posted);
            }
            return;
        }
        if (!in_array($input->getCmd('task'), array('remove', 'delete'), true)) {
            return;
        }

        $user = JFactory::getUser();
        if ($user->guest || !($user->authorise('core.admin', 'com_virtuemart') || $user->authorise('vm.paymentmethod.delete', 'com_virtuemart'))
            || !\Joomla\CMS\Session\Session::checkToken('post')) {
            throw new RuntimeException('Not authorised to delete a payment method', 403);
        }
        $ids = $input->get('cid', array(), 'array');
        if (empty($ids)) {
            $id = $input->getInt('virtuemart_paymentmethod_id', 0);
            $ids = $id ? array($id) : array();
        }

        foreach ($ids as $id) {
            $method = $this->loadTeyaPaymentMethod((int) $id);
            if (empty($method)) { continue; }
            try {
                $db = JFactory::getDBO();
                $lock = 'teya_vm_method_' . (int) $id;
                $db->setQuery('SELECT GET_LOCK(' . $db->quote($lock) . ', 5)');
                if (!(int) $db->loadResult()) { throw new RuntimeException('Payment method is busy.'); }
                register_shutdown_function(function () use ($db,$lock) {
                    $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')'); $db->loadResult();
                });
                $method = $this->loadTeyaPaymentMethod((int) $id);
                if (!$method) { continue; }
                $this->disconnectPaymentMethodRegistrations($method);
            } catch (Exception $exception) {
                $message = 'Teya could not safely disconnect this payment method. Resolve pending refunds and connection errors before deleting it.';
                $this->writeLog($message);
                if (method_exists($app, 'enqueueMessage')) {
                    $app->enqueueMessage($message, 'error');
                }
                $input->set('task', 'cancel');
                if (method_exists($app, 'redirect')) {
                    $app->redirect('index.php?option=com_virtuemart&view=paymentmethod');
                }
                return;
            }
        }
    }

    public function onExtensionBeforeUninstall($extensionId)
    {
        // Read-only guard: removing the extension never initiates bulk Teya
        // deregistration. Merchants explicitly disconnect each method first.
        $db = JFactory::getDBO();
        $db->setQuery('SELECT type, folder, element FROM #__extensions WHERE extension_id = ' . (int) $extensionId);
        $extension = $db->loadObject();
        if (!$extension || !(($extension->type === 'package' && $extension->element === 'pkg_teya_virtuemart')
            || ($extension->type === 'plugin' && (($extension->folder === 'vmpayment' && $extension->element === 'teya')
                || ($extension->folder === 'system' && $extension->element === 'teyavirtuemart'))))) {
            return;
        }
        $tables = $db->getTableList();
        if (in_array($db->getPrefix() . 'virtuemart_payment_plg_teya', $tables, true)) {
            $db->setQuery("SELECT COUNT(*) FROM #__virtuemart_payment_plg_teya WHERE teya_refund_status IN ('pending','blocked','status_pending')");
            if ((int) $db->loadResult() > 0) {
                throw new RuntimeException(TeyaVirtuemartLanguage::text('UNINSTALL_PENDING'));
            }
        }
        if (!in_array($db->getPrefix() . 'virtuemart_paymentmethods', $tables, true)) {
            return;
        }
        $db->setQuery("SELECT payment_params FROM #__virtuemart_paymentmethods WHERE payment_element = 'teya'");
        foreach ($db->loadColumn() as $params) {
            $method = (object) array('payment_params' => $params);
            $this->bindPaymentParams($method);
            foreach (array('test', 'live') as $environment) {
                if (TeyaVirtuemartCredentials::isConnected($method, $environment)) {
                    throw new RuntimeException(TeyaVirtuemartLanguage::text('UNINSTALL_CONNECTED'));
                }
            }
        }
    }

    private function loadTeyaPaymentMethod($methodId)
    {
        if ($methodId <= 0) {
            return null;
        }
        $db = JFactory::getDBO();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__virtuemart_paymentmethods'))
            ->where($db->quoteName('virtuemart_paymentmethod_id') . ' = ' . (int) $methodId)
            ->where($db->quoteName('payment_element') . ' = ' . $db->quote('teya'));
        $db->setQuery($query, 0, 1);
        $method = $db->loadObject();
        if (empty($method)) {
            return null;
        }
        $this->bindPaymentParams($method);
        return $method;
    }

    private function bindPaymentParams($method)
    {
        $params = (string) @$method->payment_params;
        if ($params === '') {
            return;
        }
        foreach (explode('|', $params) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2) {
                continue;
            }
            $decoded = json_decode($pair[1], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $method->{$pair[0]} = $decoded;
            }
        }
    }

    private function disconnectPaymentMethodRegistrations($method)
    {
        $db = JFactory::getDBO();
        if (in_array($db->getPrefix().'virtuemart_payment_plg_teya', $db->getTableList(), true)) {
            $db->setQuery('SELECT COUNT(*) FROM #__virtuemart_payment_plg_teya WHERE virtuemart_paymentmethod_id = '.(int) $method->virtuemart_paymentmethod_id
                ." AND teya_refund_status IN ('pending','blocked','status_pending')");
            if ((int) $db->loadResult() > 0) { throw new RuntimeException('Resolve pending refunds before deleting this payment method.'); }
        }
        foreach (array(TeyaVirtuemartUtils::ENV_TEST, TeyaVirtuemartUtils::ENV_LIVE) as $environment) {
            if (!TeyaVirtuemartCredentials::isConnected($method, $environment)) {
                continue;
            }
            $this->disconnectRegistration((int) $method->virtuemart_paymentmethod_id, $method, $environment);
        }

    }

    private function disconnectRegistration($methodId, $method, $environment)
    {
        $environment = TeyaVirtuemartUtils::normalizeEnvironment($environment);
        try {
            $this->apiClient($method)->deleteCurrentRegistration($this->getAccessToken($methodId, $method, $environment), $environment);
        } catch (TeyaVirtuemartApiException $exception) {
            if ($exception->getStatusCode() !== 401) {
                throw $exception;
            }
            $prefix = $environment === TeyaVirtuemartUtils::ENV_TEST ? 'test_' : '';
            $method->{$prefix . 'access_token'} = '';
            $method->{$prefix . 'access_token_expires_at'} = '';
            $this->apiClient($method)->deleteCurrentRegistration($this->getAccessToken($methodId, $method, $environment), $environment);
        }
        $prefix = $environment === TeyaVirtuemartUtils::ENV_TEST ? 'test_' : '';
        foreach (array('client_id', 'client_secret', 'payment_webhook_public_key', 'access_token', 'access_token_expires_at', 'access_token_scopes') as $key) {
            $method->{$prefix . $key} = '';
        }
        $this->savePaymentParams($methodId, $method, $environment);
    }

    private function getAccessToken($methodId, $method, $environment)
    {
        $environment = TeyaVirtuemartUtils::normalizeEnvironment($environment);
        $prefix = $environment === TeyaVirtuemartUtils::ENV_TEST ? 'test_' : '';
        $tokenKey = $prefix . 'access_token';
        $expiresKey = $prefix . 'access_token_expires_at';
        if (!empty($method->$tokenKey) && !empty($method->$expiresKey) && (int) $method->$expiresKey > time()) {
            return TeyaVirtuemartCredentials::value($method, 'access_token', $environment);
        }
        $clientId = TeyaVirtuemartCredentials::value($method, 'client_id', $environment);
        $clientSecret = TeyaVirtuemartCredentials::value($method, 'client_secret', $environment);
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Teya client credentials are not configured.');
        }
        $token = $this->apiClient($method)->requestAccessToken($clientId, $clientSecret, $environment);
        $method->$tokenKey = $token['access_token'];
        $scopesKey = $prefix . 'access_token_scopes';
        $method->$scopesKey = '';
        $method->$expiresKey = time() + max(1, ((int) ($token['expires_in'] ?? 900)) - 60);
        $this->savePaymentParams($methodId, $method, $environment, false, true);
        return $token['access_token'];
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

    private function apiClient($method)
    {
        return new TeyaVirtuemartApiClient(!empty($method->debug), array($this, 'writeLog'));
    }

    public function writeLog($message)
    {
        if (function_exists('error_log')) {
            error_log('[Teya VirtueMart Delete Sync] ' . $message);
        }
    }
}

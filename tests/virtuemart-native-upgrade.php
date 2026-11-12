<?php
/**
 * @package Teya Payments for VirtueMart
 * @license GNU General Public License version 3; see LICENSE.txt
 */
// Runs only in the disposable native test container, against its existing audit database.
define('_JEXEC', 1);
define('JDEBUG', false);
define('JPATH_BASE', '/var/www/html');
$_SERVER['HTTP_HOST'] = 'shop.example';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/administrator/index.php';
$_SERVER['SCRIPT_NAME'] = '/administrator/index.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/libraries/bootstrap.php';
require JPATH_BASE . '/configuration.php';
$config = new JConfig();
if ($config->db !== 'teya_vm_audit' || $config->host !== 'teya-vm-audit-db') {
    throw new RuntimeException('Refusing non-audit database.');
}
$completed = false;
register_shutdown_function(function () use (&$completed) {
    if (!$completed) {
        fwrite(STDERR, 'Native upgrade did not finish.' . PHP_EOL);
        exit(1);
    }
});
$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.web.administrator')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.web.administrator');
$app = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);
\Joomla\CMS\Factory::$application = $app;
$app->loadIdentity(new \Joomla\CMS\User\User(20000));
if (!$app->getIdentity()->authorise('core.admin')) {
    throw new RuntimeException('Native upgrade requires the audit administrator.');
}
$app->loadLanguage();
$app->loadDocument();
$app->createExtensionNamespaceMap();
$db = $container->get(\Joomla\Database\DatabaseInterface::class);
$db->setQuery('SELECT payment_params FROM #__virtuemart_paymentmethods WHERE virtuemart_paymentmethod_id=1');
$previousParams = $db->loadResult();
$db->setQuery('SELECT teya_refund_response FROM #__virtuemart_payment_plg_teya WHERE virtuemart_order_id=11');
$previousRefund = $db->loadResult();
$manifestPath = JPATH_ADMINISTRATOR . '/manifests/packages/pkg_teya_virtuemart.xml';
$previousVersion = (string) simplexml_load_file($manifestPath)->version;
$packagePath = getenv('TEYA_AUDIT_PACKAGE');
$unpacked = \Joomla\CMS\Installer\InstallerHelper::unpack($packagePath);
if (empty($unpacked['dir'])) {
    throw new RuntimeException('Native upgrade package extraction failed.');
}
$targetVersion = (string) simplexml_load_file($unpacked['dir'] . '/pkg_teya_virtuemart.xml')->version;
if (getenv('TEYA_AUDIT_PREVIOUS_PACKAGE') && !version_compare($previousVersion, $targetVersion, '<')) {
    throw new RuntimeException('Native upgrade fixture must install an older version first.');
}
$installer = new \Joomla\CMS\Installer\Installer();
$installer->setDatabase($db);
if (!$installer->install($unpacked['dir'])) {
    throw new RuntimeException('Native upgrade failed: ' . json_encode($app->getMessageQueue()));
}
$db->setQuery('SELECT payment_params FROM #__virtuemart_paymentmethods WHERE virtuemart_paymentmethod_id=1');
if ($db->loadResult() !== $previousParams) {
    throw new RuntimeException('Native upgrade changed encrypted payment settings.');
}
$db->setQuery('SELECT teya_refund_response FROM #__virtuemart_payment_plg_teya WHERE virtuemart_order_id=11');
if ($db->loadResult() !== $previousRefund) {
    throw new RuntimeException('Native upgrade changed the refund ledger.');
}
$db->setQuery("SELECT COUNT(*) FROM #__extensions WHERE type='package' AND element='pkg_teya_virtuemart'");
if ((int) $db->loadResult() !== 1 || (string) simplexml_load_file($manifestPath)->version !== $targetVersion) {
    throw new RuntimeException('Native upgrade package identity or version mismatch.');
}
echo 'PASS: native ZIP upgrade ' . $previousVersion . ' to ' . $targetVersion
    . ' preserves encrypted settings and order/refund data' . PHP_EOL;
$completed = true;

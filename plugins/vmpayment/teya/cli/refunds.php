<?php
/**
 * @package    Teya Payments for VirtueMart
 * @license    GNU General Public License version 3; see LICENSE.txt
 */
/** Run from the Joomla root: php plugins/vmpayment/teya/cli/refunds.php */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$teyaCliOptions = getopt('', array('check', 'site-url:'));
$siteUrl = $teyaCliOptions['site-url'] ?? (isset($teyaCliOptions['check']) ? 'https://localhost' : '');
if (!filter_var($siteUrl, FILTER_VALIDATE_URL) || parse_url($siteUrl, PHP_URL_SCHEME) !== 'https') {
    fwrite(STDERR, 'Supply --site-url=https://your-shop.example so order emails use the correct shop URL.' . PHP_EOL);
    exit(1);
}
$_SERVER['HTTP_HOST'] = parse_url($siteUrl, PHP_URL_HOST) . (parse_url($siteUrl, PHP_URL_PORT) ? ':' . parse_url($siteUrl, PHP_URL_PORT) : '');
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = rtrim((string) parse_url($siteUrl, PHP_URL_PATH), '/') . '/';
$_SERVER['SCRIPT_NAME'] = $_SERVER['REQUEST_URI'] . 'index.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
define('_JEXEC', 1);
define('JDEBUG', false);
define('JPATH_BASE', dirname(__DIR__, 4));
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/libraries/bootstrap.php';

$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.web.site')
    ->alias('JSession', 'session.web.site')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(\Joomla\CMS\Application\SiteApplication::class);
\Joomla\CMS\Factory::$application = $app;
$app->loadLanguage(\Joomla\CMS\Factory::getLanguage());
// VirtueMart versions still use the legacy names.
foreach (array('JFactory' => \Joomla\CMS\Factory::class, 'JPlugin' => \Joomla\CMS\Plugin\CMSPlugin::class,
    'JUri' => \Joomla\CMS\Uri\Uri::class, 'JText' => \Joomla\CMS\Language\Text::class,
    'JSession' => \Joomla\CMS\Session\Session::class) as $legacy => $modern) {
    if (!class_exists($legacy)) { class_alias($modern, $legacy); }
}
try {
    $db = $container->get(\Joomla\Database\DatabaseInterface::class);
    if (!in_array($db->getPrefix() . 'virtuemart_paymentmethods', $db->getTableList(), true)) {
        throw new RuntimeException('VirtueMart is not initialized: install its database tables before running refund recovery.');
    }
    require_once dirname(__DIR__).'/src/TeyaVirtuemartCompatibility.php';
    require_once JPATH_ADMINISTRATOR . '/components/com_virtuemart/helpers/config.php';
    VmConfig::loadConfig();
    vDispatcher::importVMPlugins('vmpayment');
    if (in_array('--check', $argv, true)) {
        echo 'Teya refund recovery bootstrap OK; no refunds were processed.' . PHP_EOL;
        exit;
    }
    $results = vDispatcher::directTrigger('vmpayment', 'teya', 'resumePendingRefunds', array(10));
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Teya refund recovery failed. Verify installation, plugin enablement, and database availability.' . PHP_EOL);
    exit(1);
}

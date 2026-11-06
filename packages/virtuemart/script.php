<?php
defined('_JEXEC') or die;

/** Enable the bundled plugins after Joomla has installed both successfully. */
class pkg_teya_virtuemartInstallerScript
{
    public function preflight($type, $parent)
    {
        if ($type === 'uninstall') {
            return true;
        }
        if (!is_file(JPATH_ADMINISTRATOR . '/components/com_virtuemart/helpers/config.php')) {
            throw new RuntimeException('Install VirtueMart before installing Teya Payments.');
        }
        if (!extension_loaded('openssl') || !function_exists('hash_hkdf')
            || (!function_exists('curl_init') && !ini_get('allow_url_fopen'))) {
            throw new RuntimeException('Teya Payments requires OpenSSL and an HTTPS transport.');
        }
        return true;
    }

    public function postflight($type, $parent)
    {
        if (!in_array($type, array('install', 'update', 'discover_install'), true)) {
            return;
        }
        $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        foreach (array(array('vmpayment', 'teya'), array('system', 'teyavirtuemart')) as $plugin) {
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote($plugin[0]))
                ->where($db->quoteName('element') . ' = ' . $db->quote($plugin[1]));
            $db->setQuery($query)->execute();
        }
        // Apply native payment-table upgrades during package update, not on a later settings save.
        $vmConfig = JPATH_ADMINISTRATOR.'/components/com_virtuemart/helpers/config.php';
        if (is_file($vmConfig)) {
            require_once JPATH_SITE.'/plugins/vmpayment/teya/src/TeyaVirtuemartCompatibility.php';
            require_once $vmConfig;
            VmConfig::loadConfig();
            require_once JPATH_SITE.'/plugins/vmpayment/teya/teya.php';
            $subject = \Joomla\CMS\Factory::getApplication()->getDispatcher();
            $plugin = new plgVmPaymentTeya($subject,array('name'=>'teya','type'=>'vmpayment','params'=>'{}'));
            if ($plugin->plgVmOnStoreInstallPaymentPluginTable(0) === false) {
                throw new RuntimeException('VirtueMart could not update the Teya payment table.');
            }
            $columns = $db->getTableColumns('#__virtuemart_payment_plg_teya');
            foreach (array_keys($plugin->getTableSQLFields()) as $column) {
                if (!isset($columns[$column])) { throw new RuntimeException('Teya payment table upgrade is incomplete.'); }
            }
        }
        \Joomla\CMS\Factory::getApplication()->enqueueMessage(
            'Teya Payments for VirtueMart installed. Both required plugins are enabled. Configure your Teya payment method in VirtueMart.',
            'message'
        );
    }
}

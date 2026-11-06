<?php
/**
 * @package    Teya Payments for VirtueMart
 */
defined('_JEXEC') or die('Restricted access');

jimport('joomla.form.formfield');

if (!class_exists('JFormField') && class_exists('\Joomla\CMS\Form\FormField')) {
    class_alias('\Joomla\CMS\Form\FormField', 'JFormField');
}

require_once dirname(__DIR__) . '/src/TeyaVirtuemartUtils.php';
require_once dirname(__DIR__) . '/src/TeyaVirtuemartCredentials.php';
require_once dirname(__DIR__) . '/src/TeyaVirtuemartOnboardingRenderer.php';

class JFormFieldGetteya extends JFormField
{
    public $type = 'getteya';

    protected function getLabel()
    {
        return null;
    }

    protected function getInput()
    {
        $this->loadLanguage();
        $this->loadAssets();
        $id = JFactory::getApplication()->input->get('cid', 0, 'array');
        if (is_array($id)) {
            $id = (int) reset($id);
        } else {
            $id = (int) $id;
        }
        $method = new stdClass();
        $method->virtuemart_paymentmethod_id = $id;
        $method->sandbox = 1;
        if (!$id) {
            $id = JFactory::getApplication()->input->getInt('virtuemart_paymentmethod_id', 0);
            $method->virtuemart_paymentmethod_id = $id;
        }
        if ($id) {
            // The model can be partially bound while constructing this custom field.
            // Read persisted parameters so a reload agrees with the status endpoint.
            $db = JFactory::getDBO();
            $query = $db->getQuery(true)
                ->select('payment_params')
                ->from($db->quoteName('#__virtuemart_paymentmethods'))
                ->where($db->quoteName('virtuemart_paymentmethod_id') . ' = ' . (int) $id)
                ->where($db->quoteName('payment_element') . ' = ' . $db->quote('teya'));
            $db->setQuery($query);
            $saved = $db->loadResult();
            if ($saved === null || $saved === false) {
                $method->virtuemart_paymentmethod_id = 0;
            } else {
                $allowed = array_merge(TeyaVirtuemartCredentials::keys(), array('sandbox'));
                foreach (explode('|', (string) $saved) as $part) {
                    $pair = explode('=', $part, 2);
                    if (count($pair) !== 2 || !in_array($pair[0], $allowed, true)) { continue; }
                    $value = json_decode($pair[1], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_scalar($value)) {
                        $method->{$pair[0]} = $value;
                    }
                }
            }
        }
        $renderer = new TeyaVirtuemartOnboardingRenderer();
        return $renderer->render($method, function ($methodId, $environment) {
            return $this->registrationUrl($methodId, $environment);
        }, function ($methodId, $environment) {
            return $this->disconnectUrl($methodId, $environment);
        }, function ($methodId, $environment) {
            return $this->statusUrl($methodId, $environment);
        });
    }

    private function loadAssets()
    {
        $assetBase = rtrim(JUri::root(true), '/') . '/plugins/vmpayment/teya/assets/';
        $assetPath = dirname(__DIR__) . '/assets/';
        $document = JFactory::getDocument();
        if (method_exists($document, 'addStyleSheet')) {
            $document->addStyleSheet($assetBase . 'teya-admin.css?v=' . hash_file('sha256', $assetPath . 'teya-admin.css'));
        }
        if (method_exists($document, 'addScript')) {
            $document->addScript($assetBase . 'teya-admin.js?v=' . hash_file('sha256', $assetPath . 'teya-admin.js'));
        }
    }

    private function loadLanguage()
    {
        if (class_exists('vmLanguage')) {
            vmLanguage::loadJLang('plg_vmpayment_teya', true);
        }
        $language = JFactory::getLanguage();
        $base = dirname(__DIR__);
        $language->load('plg_vmpayment_teya', $base, null, true, true);
        $language->load('plg_vmpayment_teya', $base . '/language', null, true, true);
        $language->load('plg_vmpayment_teya', JPATH_SITE, null, true, true);
        $language->load('plg_vmpayment_teya', JPATH_ADMINISTRATOR, null, true, true);
    }


    private function disconnectUrl($methodId, $environment)
    {
        $environment = TeyaVirtuemartUtils::normalizeEnvironment($environment);
        $lang = JFactory::getApplication()->input->getCmd('lang', '');
        return rtrim(JUri::root(), '/') . '/administrator/index.php?option=com_virtuemart&teya_admin=1&pm=' . (int) $methodId . '&teya_action=disconnect&environment=' . $environment . '&lang=' . $lang;
    }

    private function statusUrl($methodId, $environment)
    {
        $environment = TeyaVirtuemartUtils::normalizeEnvironment($environment);
        $lang = JFactory::getApplication()->input->getCmd('lang', '');
        return rtrim(JUri::root(), '/') . '/administrator/index.php?option=com_virtuemart&teya_admin=1&pm=' . (int) $methodId . '&teya_action=status&environment=' . $environment . '&lang=' . $lang;
    }

    private function registrationUrl($methodId, $environment)
    {
        $environment = TeyaVirtuemartUtils::normalizeEnvironment($environment);
        $lang = JFactory::getApplication()->input->getCmd('lang', '');
        return rtrim(JUri::root(), '/') . '/administrator/index.php?option=com_virtuemart&teya_admin=1&pm=' . (int) $methodId . '&teya_action=connect&environment=' . $environment . '&lang=' . $lang;
    }
}

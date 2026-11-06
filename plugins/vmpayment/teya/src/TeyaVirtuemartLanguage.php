<?php
/**
 * @package Teya Payments for VirtueMart
 * @author Webensol
 * @license GNU General Public License version 3; see LICENSE.txt
 */
defined('_JEXEC') or die('Restricted access');

/** Shared translations for checkout, administrator AJAX, browser controls and CLI recovery. */
final class TeyaVirtuemartLanguage
{
    private static $loaded;
    private static $english;

    public static function text($key, ...$arguments)
    {
        $full = 'VMPAYMENT_TEYA_' . $key;
        $message = $full;
        if (method_exists('\\Joomla\\CMS\\Factory', 'getApplication')) {
            try {
                $language = \Joomla\CMS\Factory::getApplication()->getLanguage();
                self::$loaded ??= new WeakMap();
                if (!isset(self::$loaded[$language]) || self::$loaded[$language] !== $language->getTag()) {
                    $language->load('plg_vmpayment_teya', dirname(__DIR__), null, false, true);
                    self::$loaded[$language] = $language->getTag();
                }
                $message = $language->_($full);
            } catch (Throwable $exception) {
                // A bootstrap failure must not expose framework details in an AJAX response.
            }
        }
        if ($message === $full) {
            if (self::$english === null) {
                self::$english = parse_ini_file(dirname(__DIR__) . '/language/en-GB/en-GB.plg_vmpayment_teya.ini', false, INI_SCANNER_RAW);
            }
            $message = self::$english[$full] ?? $full;
        }
        return $arguments ? vsprintf($message, $arguments) : $message;
    }

    public static function messages(array $keys)
    {
        $messages = array();
        foreach ($keys as $key) { $messages[$key] = self::text($key); }
        return htmlspecialchars(json_encode($messages, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8');
    }
}

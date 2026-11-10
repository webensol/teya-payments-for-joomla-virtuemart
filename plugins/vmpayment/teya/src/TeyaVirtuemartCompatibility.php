<?php
/**
 * @package    Teya Payments for VirtueMart
 * @license    GNU General Public License version 3; see LICENSE.txt
 */
defined('_JEXEC') or die('Restricted access');

// VirtueMart releases using Joomla's legacy library constant still need it on Joomla 6.
if (!defined('JPATH_PLATFORM') && defined('JPATH_LIBRARIES')) {
    define('JPATH_PLATFORM', JPATH_LIBRARIES);
}
// Use Joomla's own compatibility map where available, including CLI entry points.
if (defined('JPATH_PLUGINS') && class_exists('JLoader')) {
    foreach (array('compat6','compat') as $compat) {
        $path = JPATH_PLUGINS.'/behaviour/'.$compat;
        if (is_file($path.'/src/classmap/classmap.php')) {
            require_once $path.'/src/classmap/classmap.php';
            if (is_dir($path.'/classes')) { JLoader::registerNamespace('Joomla\\CMS', $path.'/classes'); }
            break;
        }
    }
}
foreach (array(
    'JTableInterface'=>'Joomla\\CMS\\Table\\TableInterface',
    'JTable'=>'Joomla\\CMS\\Table\\Table',
    'JRegistry'=>'Joomla\\Registry\\Registry',
    'JFactory'=>'Joomla\\CMS\\Factory',
    'JPlugin'=>'Joomla\\CMS\\Plugin\\CMSPlugin',
    'JUri'=>'Joomla\\CMS\\Uri\\Uri',
    'JText'=>'Joomla\\CMS\\Language\\Text',
    'JSession'=>'Joomla\\CMS\\Session\\Session',
) as $legacy=>$modern) {
    if (!class_exists($legacy) && !interface_exists($legacy) && (class_exists($modern) || interface_exists($modern))) { class_alias($modern,$legacy); }
}

if (!function_exists('utf8_strtolower') && defined('JPATH_LIBRARIES')) {
    $legacyUtf8 = JPATH_LIBRARIES.'/vendor/joomla/string/src/phputf8/utf8.php';
    if (is_file($legacyUtf8)) { require_once $legacyUtf8; }
}

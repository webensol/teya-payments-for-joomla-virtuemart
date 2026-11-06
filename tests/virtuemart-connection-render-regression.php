<?php
// Execute the real settings field and renderer against stored encrypted parameters.
define('_JEXEC', 1);
define('JPATH_SITE', dirname(__DIR__));
define('JPATH_ADMINISTRATOR', JPATH_SITE . '/administrator');
function jimport($name) {}
class JFormField {}
class vmText { static function _($text) { return $text; } }
class JUri { static function root($path = false) { return 'https://shop.example'; } static function getInstance() { return new self(); } function toString() { return 'https://shop.example/administrator'; } }
class Query {
    function select($v) { return $this; }
    function from($v) { return $this; }
    function where($v) { return $this; }
}
class DB {
    public $params;
    function getQuery($v) { return new Query(); }
    function quoteName($v) { return $v; }
    function quote($v) { return "'" . $v . "'"; }
    function setQuery($v) {}
    function loadResult() { return $this->params; }
}
class Input {
    public $cid = array(7);
    function get($name, $default, $filter) { return $this->cid; }
    function getInt($name, $default) { return 7; }
    function getCmd($name, $default) { return $default; }
}
class JFactory {
    static $db;
    static $input;
    static function getConfig() { return new class { function get($v) { return 'offline-vm-render-site-secret'; } }; }
    static function getLanguage() { return new class { function load(...$args) {} }; }
    static function getDocument() { return new stdClass(); }
    static function getDBO() { return self::$db; }
    static function getApplication() { return (object) array('input' => self::$input); }
}
require dirname(__DIR__) . '/plugins/vmpayment/teya/fields/getteya.php';
class Field extends JFormFieldGetteya {
    public function render() { return $this->getInput(); }
}
JFactory::$db = new DB(); JFactory::$input = new Input();
$secrets = (object) array('test_client_id' => 'test-id', 'test_client_secret' => 'test-secret',
    'test_payment_webhook_public_key' => 'test-key', 'client_id' => 'live-id',
    'client_secret' => 'live-secret', 'payment_webhook_public_key' => 'live-key');
$protected = TeyaVirtuemartCredentials::protect($secrets);
$parts = array('sandbox=0');
foreach ((array) $protected as $key => $value) { $parts[] = $key . '=' . json_encode($value); }
JFactory::$db->params = implode('|', $parts);
function ensure($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
foreach (array(array(7), array()) as $cid) {
    JFactory::$input->cid = $cid;
    $html = (new Field())->render();
    ensure(substr_count($html, 'teya-environment-status is-connected') === 2, 'Both saved connections render connected');
    ensure(strpos($html, 'data-active-mode="live"') !== false, 'Saved environment survives reload');
    ensure(strpos($html, 'teya:v1:') === false, 'No credential ciphertext rendered');
    ensure(strpos($html, 'test-secret') === false && strpos($html, 'live-secret') === false, 'No secrets rendered');
}
JFactory::$db->params = null;
ensure(strpos((new Field())->render(), 'VMPAYMENT_TEYA_SAVE_FIRST') !== false, 'Missing method cannot connect');
echo "PASS: persisted encrypted test/live credentials, reload rendering, both edit IDs, missing method and secret isolation\n";

<?php
require dirname(__DIR__) . '/vendor/autoload.php';
// Exercise the companion's actual native settings save guard without Joomla or a network.
define('_JEXEC',1);
define('JPATH_SITE',dirname(__DIR__));
class JPlugin {}
class vRequest {
    static $params;
    static function setVar($name, $value) { self::$params=$value; $_REQUEST[$name]=$value; }
}
class SessionToken { static $valid=true; static function checkToken($method) { return self::$valid; } }
class_alias(SessionToken::class,'Joomla\CMS\Session\Session');
class InputBag {
    public $values=array();
    function get($key,$default=null,$filter=null) { return $this->values[$key] ?? $default; }
    function set($key,$value) { $this->values[$key]=$value; }
}
class Input extends InputBag {
    public $post;
    function __construct() { $this->post=new InputBag(); }
    function getCmd($key) { return $this->get($key,''); }
    function getInt($key,$default=0) { return (int) $this->get($key,$default); }
}
class App { public $input; function __construct() { $this->input=new Input(); } function isClient($v) { return $v==='administrator'; } }
class User { public $guest=false; public $allowed=true; function authorise(...$args) { return $this->allowed; } }
class Query { function select($v) { return $this; } function from($v) { return $this; } function where($v) { return $this; } }
class DB {
    public $params; public $pending=0; private $query;
    function getQuery($v) { return new Query(); }
    function quoteName($v) { return $v; }
    function quote($v) { return "'".$v."'"; }
    function setQuery($v,...$args) { $this->query=$v; return $this; }
    function loadObject() { return (object) array('virtuemart_paymentmethod_id'=>3,'payment_element'=>'teya','payment_params'=>$this->params); }
    function loadResult() { return is_string($this->query) && strpos($this->query,'COUNT(*)')!==false ? $this->pending : 1; }
    function getPrefix() { return 'test_'; }
    function getTableList() { return array('test_virtuemart_payment_plg_teya'); }
}
class JFactory {
    static $app; static $user; static $db;
    static function getApplication() { return self::$app; }
    static function getUser() { return self::$user; }
    static function getDBO() { return self::$db; }
    static function getConfig() { return new class { function get($key) { return 'offline-admin-save-site-secret'; } }; }
}
function verify($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
function rejects($fn) { try { $fn(); } catch(RuntimeException $e) { return; } throw new RuntimeException('Expected rejection'); }
require JPATH_SITE.'/plugins/system/teyavirtuemart/teyavirtuemart.php';
JFactory::$app=new App(); JFactory::$user=new User(); JFactory::$db=new DB();
$input=JFactory::$app->input;
$input->values=array('option'=>'com_virtuemart','view'=>'paymentmethod','task'=>'apply','virtuemart_paymentmethod_id'=>3);
$original=(object) array('client_secret'=>'ORIGINAL_LIVE','test_access_token'=>'ORIGINAL_TEST','teya_onboarding'=>'{"test":{"completed":"done"}}');
$parts=array(); foreach((array)$original as $key=>$value) { $parts[]=$key.'='.json_encode($value); }
JFactory::$db->params=implode('|',$parts);
$input->post->values['params']=array('sandbox'=>0,'client_secret'=>'FORGED','teya_onboarding'=>'FORGED');
$plugin=new plgSystemTeyaVirtuemart(); $plugin->onAfterInitialise();
$saved=(object)$input->post->values['params'];
verify(TeyaVirtuemartCredentials::value($saved,'client_secret','live')==='ORIGINAL_LIVE','server secret preserved');
verify(TeyaVirtuemartCredentials::value($saved,'access_token','test')==='ORIGINAL_TEST','separate environment preserved');
verify($saved->sandbox===0 && $saved->teya_onboarding===$original->teya_onboarding,'settings change preserves callback state');
verify(strpos(json_encode($saved),'ORIGINAL_LIVE')===false && strpos(json_encode($saved),'ORIGINAL_TEST')===false,'legacy credentials encrypted on save');

verify(vRequest::$params===$input->post->values['params'],'VirtueMart filtered request uses protected credentials');
unset($input->post->values['params']);
rejects(fn()=>$plugin->onAfterInitialise());
$input->post->values['params']=(array)$saved;
SessionToken::$valid=false; rejects(fn()=>$plugin->onAfterInitialise()); SessionToken::$valid=true;
JFactory::$user->allowed=false; rejects(fn()=>$plugin->onAfterInitialise()); JFactory::$user->allowed=true;
JFactory::$db->pending=1;
rejects(fn()=>(new ReflectionMethod($plugin,'disconnectPaymentMethodRegistrations'))->invoke($plugin,(object)array('virtuemart_paymentmethod_id'=>3)));
echo "PASS: native save ACL/CSRF, credential preservation/encryption, protected callback state and pending-refund deletion guard\n";

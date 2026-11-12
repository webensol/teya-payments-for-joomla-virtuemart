<?php
// Run only via tools/run-virtuemart-native-tests.sh against its disposable database.
define('_JEXEC',1); define('JDEBUG',false); define('JPATH_BASE','/var/www/html');
$_SERVER['HTTP_HOST']='shop.example'; $_SERVER['HTTPS']='on';
$_SERVER['REQUEST_URI']='/administrator/index.php'; $_SERVER['SCRIPT_NAME']='/administrator/index.php';
$_SERVER['PHP_SELF']='/administrator/index.php';
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/libraries/bootstrap.php';
require JPATH_BASE.'/configuration.php';
$config=new JConfig();
if ($config->db!=='teya_vm_audit' || $config->host!=='teya-vm-audit-db') { throw new RuntimeException('Refusing non-audit database.'); }
$factory=new \Joomla\Database\DatabaseFactory();
$db=$factory->getDriver('mysqli',array('host'=>$config->host,'user'=>$config->user,'password'=>'','database'=>$config->db,'prefix'=>$config->dbprefix));
foreach (array('/installation/sql/mysql/base.sql','/installation/sql/mysql/extensions.sql',
 '/administrator/components/com_virtuemart/install/install.sql',
 '/administrator/components/com_virtuemart/install/install_essential_data.sql',
 '/administrator/components/com_virtuemart/install/install_required_data.sql') as $file) {
 foreach (\Joomla\Database\DatabaseDriver::splitSql(file_get_contents(JPATH_BASE.$file)) as $query) {
  if (trim($query)!=='') { $db->setQuery($query)->execute(); }
 }
}
$db->setQuery("CREATE TABLE #__virtuemart_configs (virtuemart_config_id INT PRIMARY KEY, config TEXT, created_on DATETIME, created_by INT DEFAULT 0, modified_on DATETIME, modified_by INT DEFAULT 0, locked_on DATETIME, locked_by INT DEFAULT 0)")->execute();
$nativeCompleted=false;
register_shutdown_function(function() use (&$nativeCompleted) { if (!$nativeCompleted) { fwrite(STDERR,'Native test did not finish; setup or native code exited early.'.PHP_EOL); exit(1); } });
$container=\Joomla\CMS\Factory::getContainer();
$container->alias('session','session.web.administrator')->alias('JSession','session.web.administrator')
 ->alias(\Joomla\Session\SessionInterface::class,'session.web.administrator');
$app=$container->get(\Joomla\CMS\Application\AdministratorApplication::class);
\Joomla\CMS\Factory::$application=$app;
foreach (array('JFactory'=>\Joomla\CMS\Factory::class,'JPlugin'=>\Joomla\CMS\Plugin\CMSPlugin::class,
 'JUri'=>\Joomla\CMS\Uri\Uri::class,'JText'=>\Joomla\CMS\Language\Text::class,'JSession'=>\Joomla\CMS\Session\Session::class) as $legacy=>$modern) {
 if (!class_exists($legacy)) { class_alias($modern,$legacy); }
}
$db->setQuery("INSERT INTO #__users (id,name,username,email,password,block,sendEmail,registerDate,params) VALUES (20000,'Isolated admin','isolated-admin','admin@example.invalid','',0,0,NOW(),'{}')")->execute();
$db->setQuery('INSERT INTO #__user_usergroup_map (user_id,group_id) VALUES (20000,8)')->execute();
$db->setQuery('INSERT INTO #__virtuemart_vmusers (virtuemart_user_id,virtuemart_vendor_id,user_is_vendor) VALUES (20000,1,1)')->execute();
$user=new \Joomla\CMS\User\User(20000); $app->loadIdentity($user); $app->getSession()->set('user',$user);
if (!$user->authorise('core.admin')) { throw new RuntimeException('Test administrator ACL setup failed'); }
$app->loadLanguage();
$packagePath = getenv('TEYA_AUDIT_PACKAGE');
$paymentExtensionId = 20000;
if ($packagePath) {
 $app->loadDocument();
 $app->createExtensionNamespaceMap();
 $previousPackagePath = getenv('TEYA_AUDIT_PREVIOUS_PACKAGE');
 $unpacked = \Joomla\CMS\Installer\InstallerHelper::unpack($previousPackagePath ?: $packagePath);
 if (empty($unpacked['dir'])) { throw new RuntimeException('VirtueMart package extraction failed'); }
 $installer = new \Joomla\CMS\Installer\Installer();
 $installer->setDatabase($container->get(\Joomla\Database\DatabaseInterface::class));
 if (!$installer->install($unpacked['dir'])) {
  throw new RuntimeException('VirtueMart package installation failed: '.json_encode($app->getMessageQueue()));
 }
 $db->setQuery("SELECT extension_id FROM #__extensions WHERE type='plugin' AND element='teya' AND folder='vmpayment' AND enabled=1");
 $paymentExtensionId = (int) $db->loadResult();
 $db->setQuery("SELECT COUNT(*) FROM #__extensions WHERE type='plugin' AND element='teyavirtuemart' AND folder='system' AND enabled=1");
 if (!$paymentExtensionId || (int) $db->loadResult() !== 1) { throw new RuntimeException('Package did not enable both VirtueMart plugins'); }
 echo 'PASS: Joomla installed and enabled both plugins from the VirtueMart ZIP'.PHP_EOL;
 $db->setQuery("SELECT COUNT(*) FROM #__update_sites AS u
 JOIN #__update_sites_extensions AS m ON m.update_site_id=u.update_site_id
 JOIN #__extensions AS e ON e.extension_id=m.extension_id
 WHERE e.type='package' AND e.element='pkg_teya_virtuemart' AND u.enabled=1
 AND u.location='https://raw.githubusercontent.com/webensol/teya-payments-for-joomla-virtuemart/main/updates/virtuemart.xml'");
 if ((int) $db->loadResult() !== 1) { throw new RuntimeException('Package update site was not registered'); }
 echo 'PASS: Joomla registered the VirtueMart package update site'.PHP_EOL;
}
require_once JPATH_SITE.'/plugins/vmpayment/teya/src/TeyaVirtuemartCompatibility.php';
require_once JPATH_ADMINISTRATOR.'/components/com_virtuemart/helpers/config.php';
VmConfig::loadConfig();
require_once JPATH_SITE.'/plugins/vmpayment/teya/teya.php';
$subject=$app->getDispatcher();
$plugin=new plgVmPaymentTeya($subject,array('name'=>'teya','type'=>'vmpayment','params'=>'{}'));
if (!$plugin instanceof vmPSPlugin) { throw new RuntimeException('Native plugin bootstrap failed'); }
echo 'PASS: native Joomla '.JVERSION.' and VirtueMart plugin construction'.PHP_EOL;
if ($plugin->plgVmOnStoreInstallPaymentPluginTable(0) === false) { throw new RuntimeException('Native table updater failed'); }
$columns=$db->getTableColumns('#__virtuemart_payment_plg_teya');
foreach (array('teya_refund_response','teya_refund_status','teya_environment') as $column) {
 if (!isset($columns[$column])) { throw new RuntimeException('Missing payment column '.$column); }
}
echo 'PASS: native VirtueMart plugin table schema updater'.PHP_EOL;

// Package updates migrate an older payment table without replacing existing records.
if (!$packagePath) { $db->setQuery("INSERT INTO #__extensions (extension_id,name,type,element,folder,enabled,access,client_id,manifest_cache,params,state) VALUES (20000,'Teya','plugin','teya','vmpayment',1,1,0,'{}','{}',0),(20001,'Teya companion','plugin','teyavirtuemart','system',1,1,0,'{}','{}',0)")->execute(); }
$db->setQuery("INSERT INTO #__virtuemart_payment_plg_teya (virtuemart_order_id,virtuemart_paymentmethod_id,teya_refund_key) VALUES (999,1,'preserved-key')")->execute();
$db->setQuery('ALTER TABLE #__virtuemart_payment_plg_teya DROP COLUMN teya_refund_response')->execute();
if (!class_exists('pkg_teya_virtuemartInstallerScript')) { require '/audit-packages/virtuemart/script.php'; }
(new pkg_teya_virtuemartInstallerScript())->postflight('update',null);
$columns=$db->getTableColumns('#__virtuemart_payment_plg_teya');
if (!isset($columns['teya_refund_response'])) { throw new RuntimeException('Package failed to migrate refund columns'); }
$db->setQuery('SELECT teya_refund_key FROM #__virtuemart_payment_plg_teya WHERE virtuemart_order_id=999');
if ($db->loadResult()!=='preserved-key') { throw new RuntimeException('Package update changed existing refund reference'); }
echo 'PASS: native package update repairs an older schema and preserves data'.PHP_EOL;
(new GenericTableUpdater())->createLanguageTables(array('en-GB'));
$params=(object)array('sandbox'=>1,'test_client_id'=>'isolated-test-client','test_client_secret'=>'ISOLATED_SECRET',
 'test_payment_webhook_public_key'=>'isolated-key','test_access_token'=>'ISOLATED_TOKEN','test_access_token_expires_at'=>time()+600,
 'status_pending'=>'U','status_success'=>'C','status_failed'=>'X','status_refund'=>'R');
$params=TeyaVirtuemartCredentials::protect($params);
$encoded=array(); foreach((array)$params as $key=>$value) { $encoded[]=$key.'='.json_encode($value); }
$db->setQuery('INSERT INTO #__virtuemart_paymentmethods (virtuemart_paymentmethod_id,virtuemart_vendor_id,payment_jplugin_id,payment_element,payment_params,published) VALUES (1,1,'.$paymentExtensionId.','.$db->quote('teya').','.$db->quote(implode('|',$encoded)).',1)')->execute();
$db->setQuery("INSERT INTO #__virtuemart_paymentmethods_en_gb (virtuemart_paymentmethod_id,payment_name,payment_desc,slug) VALUES (1,'Isolated Teya','','isolated-teya')")->execute();
$native=VmModel::getModel('paymentmethod'); $method=$native->getPayment(1);
if (!TeyaVirtuemartCredentials::isConnected($method,'test')) { throw new RuntimeException('Native settings model loses stored connection'); }
echo 'PASS: native VirtueMart model binds encrypted persisted credentials'.PHP_EOL;
$token=\Joomla\CMS\Session\Session::getFormToken();
$_POST=array($token=>1,'params'=>array('sandbox'=>0,'merchant_success_email'=>'','test_client_secret'=>'FORGED','test_access_token'=>'FORGED')); $_REQUEST=$_POST;
// Earlier extensions can populate VirtueMart's filtered request cache before this hook.
vRequest::setVar('params', $_POST['params']);
if (vRequest::getHtml('params', '')['test_client_secret'] !== 'FORGED') {
 throw new RuntimeException('Native stale settings cache fixture failed');
}
$app->input->set('option','com_virtuemart'); $app->input->set('view','paymentmethod');
$app->input->set('task','apply'); $app->input->set('virtuemart_paymentmethod_id',1);
$app->input->post->set($token,1); $app->input->post->set('params',$_POST['params']);
require_once JPATH_SITE.'/plugins/system/teyavirtuemart/teyavirtuemart.php';
$companion=new plgSystemTeyaVirtuemart($subject,array('name'=>'teyavirtuemart','type'=>'system','params'=>'{}'));
$companion->onAfterInitialise();
if (vRequest::getPost()['params']['test_client_secret'] !== 'FORGED') {
 throw new RuntimeException('Native save unexpectedly rewrote PHP POST');
}
$controllerParams = vRequest::getHtml('params', '');
if (TeyaVirtuemartCredentials::value((object)$controllerParams,'client_secret','test') !== 'ISOLATED_SECRET') {
 throw new RuntimeException('Native controller did not receive protected settings');
}

$data=array('virtuemart_paymentmethod_id'=>1,'virtuemart_vendor_id'=>1,'payment_jplugin_id'=>$paymentExtensionId,'payment_name'=>'Isolated Teya','published'=>1,'params'=>vRequest::getHtml('params',''));
$id=$native->store($data);
if ((int)$id!==1) { throw new RuntimeException('Native settings save failed: '.json_encode($app->getMessageQueue())); }
$db->setQuery('SELECT payment_params FROM #__virtuemart_paymentmethods WHERE virtuemart_paymentmethod_id=1');
$saved=$db->loadResult();
if (strpos($saved,'ISOLATED_SECRET')!==false || strpos($saved,'ISOLATED_TOKEN')!==false || strpos($saved,'teya:v1:')===false) { throw new RuntimeException('Native settings save lost credential encryption'); }
$fresh=(new ReflectionMethod($companion,'loadTeyaPaymentMethod'))->invoke($companion,1);
if (!TeyaVirtuemartCredentials::isConnected($fresh,'test') || (int)$fresh->sandbox!==0) { throw new RuntimeException('Native settings save lost connection or mode'); }
echo 'PASS: native configuration save preserves encrypted credentials and changes only selected settings'.PHP_EOL;
// Full native order persistence with a fake Teya transport: no outbound network or real funds.
class NativeFakeTeyaApi {
 public $refunds=array(); public $calls=array(); public $publicKey; public $tokens=0; public $sessionId='native-session'; public $sessionResponses=array(); public $sessionLookups=0;
 function fetchRegistrationPublicKey($env) { return $this->publicKey; }
 function deleteCurrentRegistration($token,$env) { $this->calls[]=array('disconnect',$env); }
 function createCheckoutSession($payload,$token,$environment,$key) { $this->calls[]=array('checkout',$key); return array('session_id'=>$this->sessionId,'session_url'=>'https://checkout.example.invalid/session'); }
 function requestAccessToken(...$args) { ++$this->tokens; return array('access_token'=>'ISOLATED_REFRESHED','expires_in'=>600); }
 function getCheckoutSession($session,$token,$environment) {
  ++$this->sessionLookups;
  if ($session !== $this->sessionId || $environment !== 'test') { throw new RuntimeException('Unexpected native session lookup'); }
  return array_shift($this->sessionResponses);
 }
 function createRefund($payload,$token,$environment,$key) { $this->calls[]=array('refund',$key,$payload); $result=array_shift($this->refunds); if ($result instanceof Throwable) { throw $result; } return $result; }
}
class NativeAuditedPlugin extends plgVmPaymentTeya { public $fake; protected function apiClient($method) { return $this->fake; } }
$nativePlugin=new NativeAuditedPlugin($subject,array('name'=>'teya','type'=>'vmpayment','params'=>'{}')); $nativePlugin->fake=new NativeFakeTeyaApi();
function callNative($object,$method,...$args) { return (new ReflectionMethod($object,$method))->invoke($object,...$args); }
function nativeCheck($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
VmConfig::set('inv_os',array()); VmConfig::set('inv_osr',array()); VmConfig::set('email_os_s',array()); VmConfig::set('email_os_v',array());
$db->setQuery("SELECT virtuemart_currency_id FROM #__virtuemart_currencies WHERE currency_code_3='EUR'"); $euro=(int)$db->loadResult();
$db->setQuery("INSERT INTO #__virtuemart_vendors (virtuemart_vendor_id,vendor_name,vendor_currency) VALUES (1,'Isolated vendor',".$euro.")")->execute();
$db->setQuery("INSERT INTO #__virtuemart_vendors_en_gb (virtuemart_vendor_id,vendor_store_name) VALUES (1,'Isolated shop')")->execute();
$db->setQuery("INSERT INTO #__virtuemart_orders (virtuemart_order_id,virtuemart_user_id,virtuemart_vendor_id,order_number,order_pass,order_total,order_currency,order_status,virtuemart_paymentmethod_id,order_language,created_on) VALUES (10,20000,1,'AUDIT10','isolated-order-pass',10,".$euro.",'P',1,'en-GB',NOW())")->execute();
$db->setQuery("INSERT INTO #__virtuemart_order_userinfos (virtuemart_order_id,address_type,email,first_name,last_name) VALUES (10,'BT','customer@example.invalid','Test','Customer')")->execute();
$key=openssl_pkey_new(array('private_key_bits'=>2048)); $public=openssl_pkey_get_details($key)['key'];
$fresh->test_payment_webhook_public_key=$public; $fresh->sandbox=1;
callNative($nativePlugin,'savePaymentParams',1,$fresh,'test',true);
$method=$nativePlugin->getVmPluginMethod(1,false);
$order=VmModel::getModel('orders')->getOrder(10);
$payload=TeyaVirtuemartCheckoutBuilder::build(new stdClass(),$order,$method,array('success_url'=>'https://shop.example/ok','failure_url'=>'https://shop.example/fail','cancel_url'=>'https://shop.example/cancel'));
$result=callNative($nativePlugin,'createOrResumeCheckout',$order,$method,'test',$payload);
nativeCheck($result['session_id']==='native-session','Native checkout failed');
$result=callNative($nativePlugin,'createOrResumeCheckout',$order,$method,'test',$payload);
nativeCheck(count($nativePlugin->fake->calls)===1,'Native checkout duplicate called transport');
echo 'PASS: native checkout persistence and session reuse'.PHP_EOL;
$event=array('event'=>'payment.succeeded.v1','data'=>array('status'=>'SUCCESS','transaction_id'=>'native-transaction','session_id'=>'native-session','amount'=>array('value'=>1000,'currency'=>'EUR')));
$raw=json_encode($event); openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256);
$method->test_payment_webhook_public_key='replaced-key';
callNative($nativePlugin,'handlePaymentNotification',$method,$raw,base64_encode($signature));
$db->setQuery('SELECT order_status FROM #__virtuemart_orders WHERE virtuemart_order_id=10'); nativeCheck($db->loadResult()==='C','Native signed payment did not confirm order');
$db->setQuery('SELECT COUNT(*) FROM #__virtuemart_order_histories WHERE virtuemart_order_id=10'); $before=(int)$db->loadResult();
callNative($nativePlugin,'handlePaymentNotification',$method,$raw,base64_encode($signature));
$db->setQuery('SELECT COUNT(*) FROM #__virtuemart_order_histories WHERE virtuemart_order_id=10'); nativeCheck((int)$db->loadResult()===$before,'Duplicate callback repeated history');
echo 'PASS: native signed callback commits order status and duplicates remain idempotent'.PHP_EOL;
$nativePlugin->fake->refunds[]=array('_http_status'=>202);
$result=callNative($nativePlugin,'processRefund',10,$method,'2.00','native-refund-one'); nativeCheck($result['pending'],'202 must remain pending');
$db->setQuery('SELECT * FROM #__virtuemart_payment_plg_teya WHERE virtuemart_order_id=10'); $row=$db->loadObject();
$ledger=TeyaVirtuemartRefundState::load($row); $ledger['pending']['next_attempt']=0;
$update=(object)array('id'=>$row->id,'teya_refund_response'=>json_encode($ledger));
$db->updateObject('#__virtuemart_payment_plg_teya',$update,'id');
$nativePlugin->fake->refunds[]=array('_http_status'=>201,'status'=>'SUCCESS');
$result=callNative($nativePlugin,'processRefund',10,$method,null,null,true); nativeCheck($result['remaining']===800 && !$result['pending'],'Native partial refund not confirmed');
$nativePlugin->fake->refunds[]=array('_http_status'=>201,'status'=>'SUCCESS');
$result=callNative($nativePlugin,'processRefund',10,$method,'8.00','native-refund-two'); nativeCheck($result['remaining']===0,'Native full refund balance wrong');
$html=$nativePlugin->plgVmOnShowOrderBEPayment(10,1);
nativeCheck(strpos($html,'teya-refund-sync')!==false,'Confirmed refund missing status-only recovery control');
$before=count($nativePlugin->fake->calls);
$result=callNative($nativePlugin,'processRefund',10,$method,null,null,true);
nativeCheck($result['remaining']===0 && count($nativePlugin->fake->calls)===$before,'Status recovery issued another refund');
callNative($nativePlugin,'markOrderRefunded',10,$method);
$db->setQuery('SELECT order_status FROM #__virtuemart_orders WHERE virtuemart_order_id=10'); nativeCheck($db->loadResult()==='R','Native full refund status not saved');
echo 'PASS: native pending recovery, partial/full refund ledger and refunded order status'.PHP_EOL;
// Exercise the native return hook with a real Joomla session and VirtueMart cart.
$db->setQuery("INSERT INTO #__virtuemart_orders (virtuemart_order_id,virtuemart_user_id,virtuemart_vendor_id,order_number,order_pass,order_total,order_currency,order_status,virtuemart_paymentmethod_id,order_language,created_on) VALUES (11,0,1,'AUDIT11','isolated-order-pass',10,".$euro.",'P',1,'en-GB',NOW())")->execute();
$db->setQuery("INSERT INTO #__virtuemart_order_userinfos (virtuemart_order_id,address_type,email,first_name,last_name) VALUES (11,'BT','return@example.invalid','Test','Customer')")->execute();
$nativePlugin->fake->sessionId = 'native-return-session';
$returnOrder = VmModel::getModel('orders')->getOrder(11);
$returnPayload = TeyaVirtuemartCheckoutBuilder::build(null, $returnOrder, $method, array('success_url'=>'https://shop.example/ok','failure_url'=>'https://shop.example/fail','cancel_url'=>'https://shop.example/cancel'));
callNative($nativePlugin, 'createOrResumeCheckout', $returnOrder, $method, 'test', $returnPayload);
$nativePlugin->fake->sessionResponses[] = array('_http_status'=>200,'session_id'=>'native-return-session','session_status'=>'COMPLETED','payment_status'=>'SUCCESS',
 'transaction_id'=>'native-return-transaction','type'=>'SALE','merchant_reference'=>'joomla_virtuemart#AUDIT11','amount'=>array('value'=>1000,'currency'=>'EUR'));
require_once JPATH_SITE.'/components/com_virtuemart/helpers/cart.php';
$siteApp = $container->get(\Joomla\CMS\Application\SiteApplication::class);
$siteApp->loadIdentity(new \Joomla\CMS\User\User(0));
$siteApp->loadLanguage();
\Joomla\CMS\Factory::$application = $siteApp;
try {
 $siteApp->input->set('pm',1);
 $siteApp->input->set('on','AUDIT11');
 $returnHtml = '';
 nativeCheck($nativePlugin->plgVmOnPaymentResponseReceived($returnHtml) === false && $nativePlugin->fake->sessionLookups === 0,
  'Guest without checkout cart reached lookup');
 $cart = VirtueMartCart::getCart();
 $cart->virtuemart_order_id = 11;
 $cart->order_number = 'AUDIT11';
 $cart->setCartIntoSession();
 $cart = VirtueMartCart::getCart(true);
 nativeCheck($nativePlugin->plgVmOnPaymentResponseReceived($returnHtml) === true && $nativePlugin->fake->sessionLookups === 1,
  'Guest with the persisted cart could not confirm payment');
 nativeCheck((int) $cart->virtuemart_order_id === 0, 'Successful return did not clear its checkout cart');
 $nativePlugin->plgVmOnPaymentResponseReceived($returnHtml);
 nativeCheck($nativePlugin->fake->sessionLookups === 1, 'Repeated return queried Teya again');
 $siteApp->input->set('on','AUDIT10');
 nativeCheck($nativePlugin->plgVmOnPaymentResponseReceived($returnHtml) === false, 'Guest could read a registered shopper order');
 $siteApp->loadIdentity($user);
 nativeCheck($nativePlugin->plgVmOnPaymentResponseReceived($returnHtml) === true, 'Registered order owner could not read payment status');
 nativeCheck($nativePlugin->fake->sessionLookups === 1, 'Webhook-confirmed owner return called the provider again');

} finally {
 \Joomla\CMS\Factory::$application = $app;
}
$db->setQuery('SELECT order_status FROM #__virtuemart_orders WHERE virtuemart_order_id=11');
nativeCheck($db->loadResult()==='C','Native return did not confirm the order');
$db->setQuery('SELECT COUNT(*) FROM #__virtuemart_order_histories WHERE virtuemart_order_id=11'); $before=(int)$db->loadResult();
$returnEvent = array('event'=>'payment.succeeded.v1','data'=>array('status'=>'SUCCESS','session_id'=>'native-return-session','transaction_id'=>'native-return-transaction','amount'=>array('value'=>1000,'currency'=>'EUR')));
$returnRaw = json_encode($returnEvent); openssl_sign($returnRaw,$returnSignature,$key,OPENSSL_ALGO_SHA256);
callNative($nativePlugin,'handlePaymentNotification',$method,$returnRaw,base64_encode($returnSignature));
$db->setQuery('SELECT COUNT(*) FROM #__virtuemart_order_histories WHERE virtuemart_order_id=11');
nativeCheck((int)$db->loadResult()===$before,'Late webhook duplicated return confirmation');
$nativePlugin->fake->refunds[]=array('_http_status'=>201,'status'=>'SUCCESS');
$result=callNative($nativePlugin,'processRefund',11,$method,'2.00','native-return-refund');
nativeCheck($result['remaining']===800,'Return-confirmed payment could not be partially refunded');
echo 'PASS: native guest authorization, checkout return confirmation, late webhook and refund'.PHP_EOL;
// Native backend refund panel and connection UI execute on real Joomla form classes.
$html=$nativePlugin->plgVmOnShowOrderBEPayment(10,1);
nativeCheck(strpos($html,'ISOLATED_TOKEN')===false && strpos($html,'ISOLATED_SECRET')===false,'Native order panel exposed credentials');
require_once JPATH_SITE.'/plugins/vmpayment/teya/fields/getteya.php';
$field=new JFormFieldGetteya(); $html=(new ReflectionMethod($field,'getInput'))->invoke($field);
nativeCheck(strpos($html,'teya-onboarding-container')!==false && strpos($html,'ISOLATED_SECRET')===false && strpos($html,'teya:v1:')===false,'Native connection UI failed or exposed credentials');
echo 'PASS: native administrator connection and order panels render without credentials'.PHP_EOL;
$nonce=callNative($nativePlugin,'withConnectionLock',$method,function($fresh) use($nativePlugin) {
 $nonce=TeyaVirtuemartOnboardingState::begin($fresh,'test');
 callNative($nativePlugin,'savePaymentParams',1,$fresh,'test'); return $nonce;
});
$nativePlugin->fake->publicKey=$public;
$registration=json_encode(array('client_id'=>'reconnected-client','client_secret'=>'RECONNECTED_SECRET','payment_webhook_public_key'=>$public));
openssl_sign($registration,$regSignature,$key,OPENSSL_ALGO_SHA256);
callNative($nativePlugin,'handleRegistrationNotification',$method,'test',$registration,base64_encode($regSignature),$nonce);
$tokenCount=$nativePlugin->fake->tokens;
callNative($nativePlugin,'handleRegistrationNotification',$method,'test',$registration,base64_encode($regSignature),$nonce);
nativeCheck($nativePlugin->fake->tokens===$tokenCount,'Native duplicate registration obtained another token');
$db->setQuery('SELECT payment_params FROM #__virtuemart_paymentmethods WHERE virtuemart_paymentmethod_id=1');
nativeCheck(strpos($db->loadResult(),'RECONNECTED_SECRET')===false,'Native registration stored plaintext');
echo 'PASS: native signed registration, encrypted credential persistence and callback replay protection'.PHP_EOL;
// A distinct DB connection cannot take a method lock while an operation holds it.
$other=$factory->getDriver('mysqli',array('host'=>$config->host,'user'=>$config->user,'password'=>'','database'=>$config->db,'prefix'=>$config->dbprefix));
callNative($nativePlugin,'withConnectionLock',$method,function($fresh) use($other) {
 $other->setQuery("SELECT GET_LOCK('teya_vm_method_1',0)"); nativeCheck((int)$other->loadResult()===0,'Native method lock did not exclude another connection');
});
echo 'PASS: real MariaDB method locking excludes concurrent connections'.PHP_EOL;
$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg(JPATH_SITE.'/plugins/vmpayment/teya/cli/refunds.php').' --check';
exec($command.' 2>&1',$cliOutput,$cliExit);
nativeCheck($cliExit===0 && strpos(implode(' ',$cliOutput),'bootstrap OK')!==false,'Native CLI recovery bootstrap failed: '.implode(' ',$cliOutput));
echo 'PASS: native CLI refund recovery --check'.PHP_EOL;
if ($packagePath) {
 $upgradeCommand=escapeshellarg(PHP_BINARY).' '.escapeshellarg('/audit-tests/virtuemart-native-upgrade.php');
 exec($upgradeCommand.' 2>&1',$upgradeOutput,$upgradeExit);
 nativeCheck($upgradeExit===0,'Native upgrade subprocess failed: '.implode(' ', $upgradeOutput));
 echo implode(PHP_EOL,$upgradeOutput).PHP_EOL;
}
$nativeCompleted=true;

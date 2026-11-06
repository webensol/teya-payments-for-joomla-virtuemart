<?php
// Isolated Docker audit fixture; contains no merchant credentials.
class JConfig {
 public $host='teya-vm-audit-db'; public $user='root'; public $password='';
 public $db='teya_vm_audit'; public $dbprefix='audit_'; public $dbtype='mysqli';
 public $secret='isolated-virtuemart-audit-site-secret';
 public $sitename='VirtueMart isolated tests'; public $live_site='https://shop.example';
 public $log_path='/tmp'; public $tmp_path='/tmp'; public $caching=0;
 public $cache_handler='file'; public $session_handler='database';
 public $offline=0; public $debug=0; public $mailonline=0;
 public $mailer='mail'; public $sendmail='/bin/false';
 public $mailfrom='test@example.invalid'; public $fromname='Isolated audit';
 public $lifetime=15; public $force_ssl=0; public $sef=0; public $error_reporting='default';
 public $access=1; public $list_limit=20; public $offset='UTC';
}

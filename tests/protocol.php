<?php
require __DIR__.'/../app/PaymentDrivers/MakePay/Protocol.php';
use App\PaymentDrivers\MakePay\Protocol;
$count = 0;
function check($value, $message) { global $count; if (!$value) throw new RuntimeException($message); $count++; }
$raw = '{"session":{"status":"complete"}}'; $secret = 'local-test-only'; $now = time();
$header = 't='.$now.',v1='.hash_hmac('sha256', $now.'.'.$raw, $secret);
check(Protocol::verify($raw, $header, $secret), 'Valid signature');
check(!Protocol::verify($raw.' ', $header, $secret), 'Tampered body rejected');
check(!Protocol::verify($raw, $header, $secret, $now+301), 'Old delivery rejected');
check(!Protocol::verify($raw, $header, $secret, $now-301), 'Future delivery rejected');
check(!Protocol::verify($raw, $header, ''), 'Empty secret rejected');
check(!Protocol::verify($raw, 't='.$now.',v1=bad', $secret), 'Malformed signature rejected');
check(Protocol::amount('129.99') === '129.99', 'Amount preserved');
check(Protocol::amount('10') === '10.00', 'Whole amount normalized');
foreach (['0', '-1', '1.234', 'NaN', '1e4'] as $bad) { try { Protocol::amount($bad); check(false, 'Bad amount'); } catch (InvalidArgumentException) { check(true, 'Invalid amount rejected'); } }
$saved = ['uid'=>'mp_1','amount'=>'129.99','currency'=>'EUR','precision'=>2];
$event = ['paymentLink'=>['uid'=>'mp_1','merchantOrderId'=>'ninja_testhash','amount'=>'129.99','currency'=>'USDC','fiatCurrency'=>'EUR','fiatAmount'=>'129.99'],'session'=>['id'=>'s1']];
check(Protocol::matches($event,$saved,'testhash'),'Matching reference');
foreach (['uid'=>'other','merchantOrderId'=>'ninja_other','fiatAmount'=>'1.00','fiatCurrency'=>'AED'] as $key=>$value) { $changed=$event;$changed['paymentLink'][$key]=$value;check(!Protocol::matches($changed,$saved,'testhash'),'Mismatch rejected'); }
check(Protocol::amount('1.234',3)==='1.234','Three decimal currency preserved');
check(Protocol::amount('1234',0)==='1234','Zero decimal currency preserved');
check(Protocol::amount('00010.00')==='10.00','Decimal normalization');
$missing=$event;unset($missing['paymentLink']['fiatCurrency']);check(!Protocol::matches($missing,$saved,'testhash'),'Settlement token cannot stand in for fiat currency');
echo "$count protocol checks passed.\n";

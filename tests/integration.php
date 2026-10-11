<?php
// Runs against a disposable, migrated and seeded upstream Invoice Ninja app.
$target = realpath($argv[1] ?? '');
if (!$target || !is_file($target.'/artisan')) throw new RuntimeException('Pass the local Invoice Ninja path.');
require $target.'/vendor/autoload.php';
$app = require $target.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error) { fwrite(STDERR, $error->getMessage().PHP_EOL); exit(1); });
if (!app()->environment(['local', 'testing'])) throw new RuntimeException('Only disposable local/testing applications are allowed.');
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Event;
use App\Models\CompanyGateway;
use App\Models\Gateway;
use App\Models\GatewayType;
use App\Models\PaymentHash;
use App\Models\Payment;
use App\PaymentDrivers\MakePayPaymentDriver;
Queue::fake(); Mail::fake(); Event::fake(); Http::preventStrayRequests();
$fixture = new class { use Tests\MockAccountData; };
$fixture->makeTestData();
$eur=App\Models\Currency::where('code','EUR')->firstOrFail();
$clientSettings=$fixture->client->settings;$clientSettings->currency_id=$eur->id;$clientSettings->client_online_payment_notification=true;$clientSettings->pdf_email_attachment=false;$clientSettings->document_email_attachment=false;$fixture->client->settings=$clientSettings;$fixture->client->save();
$gateway = Gateway::where('provider','MakePay')->firstOrFail();
$cg = new CompanyGateway(); $cg->company_id=$fixture->company->id; $cg->user_id=$fixture->user->id; $cg->gateway_key=$gateway->key;
$cg->setConfig(['companyId'=>'test-company','apiKeyId'=>'test-key','apiKeySecret'=>'test-api-secret','webhookSecret'=>'test-webhook-secret']);
$cg->fees_and_limits = (object) ['4'=>new App\DataMapper\FeesAndLimits()];
$cg->save();
$amount=number_format($fixture->invoice->balance,2,'.','');
$hash=bin2hex(random_bytes(16));
$ph=new PaymentHash();$ph->hash=$hash;$ph->fee_total=0;$ph->fee_invoice_id=$fixture->invoice->id;
$ph->data=(object)['amount_with_fee'=>$amount,'credits'=>0,'invoices'=>[(object)['invoice_id'=>$fixture->invoice->hashed_id,'invoice_number'=>$fixture->invoice->number,'amount'=>(float)$amount]]];$ph->save();
$driver=new MakePayPaymentDriver($cg,$fixture->client);$driver->payment_hash=$ph;$driver->setPaymentMethod(GatewayType::CRYPTO);
if (in_array('--portal-fixture', $argv, true)) {
    $settings = $fixture->company->settings;
    $settings->name = 'MakePay Local QA';
    $fixture->company->settings = $settings; $fixture->company->save();
    $fixture->client->name = 'Local Test Customer'; $fixture->client->save();
    $invitation = $fixture->invoice->invitations->first();
    file_put_contents(__DIR__.'/../output-fixture.json', json_encode(['company_key'=>$fixture->company->company_key,
      'gateway_id'=>$cg->hashed_id, 'invoice_id'=>$fixture->invoice->hashed_id, 'invitation_key'=>$invitation->key,
      'payment_hash'=>$hash, 'amount'=>$amount], JSON_PRETTY_PRINT));
    echo 'Created unpaid local fixture. Open '.url('/client/invoice/'.$invitation->key).PHP_EOL;
    exit(0);
}
$count=0;
function verify($bool,$message){global$count;if(!$bool)throw new RuntimeException($message);$count++;echo "PASS $message\n";}
verify($cg->driver() instanceof MakePayPaymentDriver,'Native driver discovery');
verify(isset($gateway->getMethods()[GatewayType::CRYPTO]),'Native gateway settings metadata');
$gatewayApi=(new App\Transformers\GatewayTransformer())->transform($gateway);
verify(array_keys(json_decode($gatewayApi['fields'],true,512,JSON_THROW_ON_ERROR))===['companyId','apiKeyId','apiKeySecret','webhookSecret'],'Merchant gateway API renders all four connection fields');
verify(in_array(GatewayType::CRYPTO, array_column($fixture->client->service()->getPaymentMethods((float)$amount), 'gateway_type_id')), 'Native client portal offers crypto payment');
Http::fake(['www.makecrypto.io/*'=>Http::response(['paymentLink'=>['uid'=>'mp_'.$hash,'publicUrl'=>'https://www.makepay.io/pay/demo']],201)]);
verify($driver->auth()==='ok','Credential validation');
$r=$driver->processPaymentResponse(Illuminate\Http\Request::create('/','POST',['amount'=>'0.01','currency'=>'AED']));
verify($r->getTargetUrl()==='https://www.makepay.io/pay/demo','Hosted checkout redirect');
Http::assertSent(fn($r)=>$r->method()==='POST' && $r['payload']['amount']===$amount && $r['payload']['fiatCurrency']==='EUR' && !isset($r['payload']['currency']) && $r['source']==='invoice-ninja');
verify(true,'EUR server price ignores tampered browser fields and preserves fiat currency');
$driver->payment_hash=$ph->fresh();$driver->processPaymentResponse(Illuminate\Http\Request::create('/','POST'));Http::assertSentCount(2);verify(true,'Repeated checkout reuses the same payment link');
$invitation=$fixture->invoice->invitations->first();
$variables=(new App\Utils\HtmlEngine($invitation))->makeValues();
verify(($variables['$view_url']??'')===$invitation->getLink(),'PDF and email use native invitation URL');
foreach (['email-invoice'=>'invoice','email-reminder'=>'reminder1'] as $example=>$template) {
    $engine=(new App\Mail\Engine\InvoiceEmailEngine($invitation,$template,['subject'=>'Invoice $number','body'=>file_get_contents(__DIR__.'/../examples/'.$example.'.html')]))->build();
    verify(str_contains($engine->getBody(),'Pay with MakePay') && str_contains($engine->getBody(),'href="'.$invitation->getLink().'"') && !str_contains($engine->getBody(),'$view_url'),'Native '.$template.' email renders the MakePay button');
}
$oldAppUrl=config('ninja.app_url');
config(['ninja.app_url'=>'https://billing.example.com']);
$baseDesign=App\Models\Design::findOrFail(2);
$designBody=file_get_contents(config('ninja.designs.base_path').strtolower($baseDesign->name).'.html');
$pdfButton=file_get_contents(__DIR__.'/../examples/pdf-payment-button.html');
$designBody=str_contains($designBody,'</body>')?str_replace('</body>',$pdfButton.'</body>',$designBody):$designBody.$pdfButton;
$design=$baseDesign->replicate();$design->name='MakePay test PDF';$design->is_custom=true;$design->company_id=$fixture->company->id;$design->user_id=$fixture->user->id;
$design->design=(object)['includes'=>'','header'=>'','body'=>$designBody,'footer'=>''];$design->save();
$fixture->invoice->design_id=$design->id;$fixture->invoice->save();
$invitation->unsetRelation('invoice');
$pdfHtml=(new App\Services\Pdf\PdfService($invitation))->boot()->getHtml();
verify(str_contains($pdfHtml,'href="'.$invitation->getLink().'"') && str_contains($pdfHtml,'Pay with MakePay'),'Native PDF design and sanitizer retain the clickable MakePay button');
verify(str_contains($pdfHtml,'Payment link:') && str_contains(strip_tags($pdfHtml),$invitation->getLink()),'PDF includes a visible payment URL fallback');
config(['ninja.app_url'=>$oldAppUrl]);
$localPdfHtml=(new App\Services\Pdf\PdfService($invitation))->boot()->getHtml();
verify(str_contains(strip_tags($localPdfHtml),$invitation->getLink()) && str_contains($localPdfHtml,'a.makepay-payment-button:not([href])'),'Local PDF retains the copyable URL and hides a button if its href is removed');
$e=['type'=>'makepay.payment.status_changed','paymentLink'=>['uid'=>'mp_'.$hash,'merchantOrderId'=>'ninja_'.$hash,'amount'=>$amount,'currency'=>'USDC','fiatAmount'=>$amount,'fiatCurrency'=>'EUR'],'session'=>['id'=>'session_'.$hash,'status'=>'complete']];
function signedRequest(array $e,int $offset=0){$raw=json_encode($e);$t=time()+$offset;$sig=hash_hmac('sha256',$t.'.'.$raw,'test-webhook-secret');return Illuminate\Http\Request::create('/','POST',[],[],[],['HTTP_X_MAKEPAY_SIGNATURE'=>'t='.$t.',v1='.$sig],$raw);}
$before=Payment::where('company_gateway_id',$cg->id)->count();
foreach(['underpaid','pending','failed','expired','unpaid','incomplete']as$status){$v=$e;$v['session']['status']=$status;verify($driver->processWebhookRequest(signedRequest($v))->getStatusCode()===200,'Ignore '.$status);}
verify(Payment::where('company_gateway_id',$cg->id)->count()===$before,'No payment for intermediate or failed statuses');
verify($driver->processWebhookRequest(signedRequest($e,-301))->getStatusCode()===401,'Reject expired signature');
foreach(['uid'=>'wrong','fiatAmount'=>'0.01','fiatCurrency'=>'USD']as$key=>$value){$v=$e;$v['paymentLink'][$key]=$value;verify($driver->processWebhookRequest(signedRequest($v))->getStatusCode()===400,'Reject wrong '.$key);}
$r=$driver->processWebhookRequest(signedRequest($e));verify($r->getStatusCode()===200,'Completed webhook accepted: '.$r->getContent());
verify($ph->fresh()->payment_id!==null,'Native payment record attached');
Queue::assertPushed(App\Jobs\Payment\EmailPayment::class,1);verify(true,'Native online payment receipt queued');
verify((float)$fixture->invoice->fresh()->balance===0.0,'Invoice balance cleared by native payment service');
$driver->processWebhookRequest(signedRequest($e));verify(Payment::where('company_gateway_id',$cg->id)->count()===$before+1,'Duplicate webhook creates exactly one payment');
Queue::assertPushed(App\Jobs\Payment\EmailPayment::class,1);verify(true,'Duplicate callback sends no second receipt');
$wrong=new CompanyGateway();$wrong->company_id=$fixture->company->id;$wrong->user_id=$fixture->user->id;$wrong->gateway_key=$gateway->key;$wrong->setConfig(['webhookSecret'=>'test-webhook-secret']);$wrong->save();
verify((new MakePayPaymentDriver($wrong))->processWebhookRequest(signedRequest($e))->getStatusCode()===400,'Reject cross-gateway callback');
$callbackUrl=route('payment_webhook',['company_key'=>$fixture->company->company_key,'company_gateway_id'=>$cg->hashed_id]);
$httpKernel=$app->make(Illuminate\Contracts\Http\Kernel::class);
$httpRequest=signedRequest($e);$httpRequest->initialize([],[],[],[],[],array_merge($httpRequest->server->all(),['REQUEST_URI'=>parse_url($callbackUrl,PHP_URL_PATH)]),$httpRequest->getContent());
verify($httpKernel->handle($httpRequest)->getStatusCode()===200,'Native HTTP webhook route accepts duplicate completion');
$partial = new class { use Tests\MockAccountData; }; $partial->makeTestData();
$feeCg=$cg->replicate();$feeCg->company_id=$partial->company->id;$feeCg->user_id=$partial->user->id;
$fees=$feeCg->fees_and_limits;$fees->{'4'}->fee_amount=1;$feeCg->fees_and_limits=$fees;$feeCg->save();
$quote=(new App\Services\Invoice\CalculateGatewayFee($feeCg,GatewayType::CRYPTO,$partial->invoice,5))->run();
verify((float)$quote['gross']===1.0,'Native gateway fee quote');
$partialHash=bin2hex(random_bytes(16));$partialPh=new PaymentHash();$partialPh->hash=$partialHash;
$partialPh->fee_total=$quote['gross'];$partialPh->fee_invoice_id=$partial->invoice->id;
$partialPh->data=(object)['amount_with_fee'=>'6.00','credits'=>0,'invoices'=>[(object)['invoice_id'=>$partial->invoice->hashed_id,'invoice_number'=>$partial->invoice->number,'amount'=>5]]];$partialPh->save();
$partialDriver=new MakePayPaymentDriver($feeCg,$partial->client);$partialDriver->payment_hash=$partialPh;$partialDriver->setPaymentMethod(GatewayType::CRYPTO);
Http::swap(new Illuminate\Http\Client\Factory());Http::preventStrayRequests();
Http::fake(['www.makecrypto.io/*'=>Http::response(['paymentLink'=>['uid'=>'mp_'.$partialHash,'publicUrl'=>'https://www.makepay.io/pay/partial']],201)]);
$partialDriver->processPaymentResponse(Illuminate\Http\Request::create('/','POST'));
Http::assertSent(fn($r)=>$r->method()==='POST'&&$r['payload']['amount']==='6.00');verify(true,'Partial checkout includes the native gateway fee');
$partialEvent=['paymentLink'=>['uid'=>'mp_'.$partialHash,'merchantOrderId'=>'ninja_'.$partialHash,'amount'=>'6.00','currency'=>'USDC','fiatAmount'=>'6.00','fiatCurrency'=>'USD'],'session'=>['id'=>'session_'.$partialHash,'status'=>'complete']];
$partialResponse=$partialDriver->processWebhookRequest(signedRequest($partialEvent));
verify($partialResponse->getStatusCode()===200,'Partial payment callback accepted: '.$partialResponse->getContent());
verify((float)$partial->invoice->fresh()->balance===5.0,'Partial payment leaves the correct invoice balance');
$partialDriver->processWebhookRequest(signedRequest($partialEvent));
verify(count(array_filter($partial->invoice->fresh()->line_items,fn($line)=>($line->unit_code??'')===$partialHash))===1,'Gateway fee is recorded once on duplicate delivery');
file_put_contents(__DIR__.'/../output-fixture.json',json_encode(['company_key'=>$fixture->company->company_key,'gateway_id'=>$cg->hashed_id,'invoice_id'=>$fixture->invoice->hashed_id,'invitation_key'=>$fixture->invoice->invitations->first()->key,'payment_hash'=>$hash,'amount'=>$amount],JSON_PRETTY_PRINT));
echo "$count native integration checks passed.\n";

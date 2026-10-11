<?php
namespace App\PaymentDrivers;

use App\Exceptions\PaymentFailed;
use App\Models\Client;
use App\Models\GatewayType;
use App\Models\Payment;
use App\Models\PaymentHash;
use App\Models\PaymentType;
use App\PaymentDrivers\MakePay\Protocol;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MakePayPaymentDriver extends BaseDriver
{
    public $refundable = false;
    public $token_billing = false;
    public $can_authorise_credit_card = false;
    public static $methods = [GatewayType::CRYPTO => self::class];

    public function gatewayTypes(): array { return [GatewayType::CRYPTO]; }
    public function setPaymentMethod($payment_method_id) { $this->payment_method = GatewayType::CRYPTO; return $this; }
    public function init() { return $this; }
    public function auth(): string
    {
        try {
            if (!$this->company_gateway->getConfigField('webhookSecret')) return 'error';
            $this->api('GET'); return 'ok';
        } catch (\Throwable) { return 'error'; }
    }
    private function api(string $method, ?array $body = null): array
    {
        $id = (string) $this->company_gateway->getConfigField('companyId');
        $key = (string) $this->company_gateway->getConfigField('apiKeyId');
        $secret = (string) $this->company_gateway->getConfigField('apiKeySecret');
        if ($id === '' || $key === '' || $secret === '') throw new PaymentFailed('Configure MakePay company ID and API credentials.');
        // Production destination is fixed; credentials never follow redirects.
        $request = Http::withOptions(['allow_redirects' => false])->acceptJson()->timeout(20)->connectTimeout(5)
            ->withHeaders(['X-MakeCrypto-Key-Id' => $key, 'X-MakeCrypto-Key-Secret' => $secret]);
        $url = 'https://www.makecrypto.io/api/partner/v1/companies/'.rawurlencode($id).'/payment-links';
        $response = $method === 'GET' ? $request->get($url) : $request->post($url, $body);
        if (!$response->successful()) throw new PaymentFailed('MakePay could not create checkout. Check credentials, settlement and minimum payment amount.');
        return $response->json();
    }
    public function processPaymentView(array $data) { return render('gateways.makepay.pay', $this->processPaymentViewData($data)); }
    public function livewirePaymentView(array $data): string { return 'gateways.makepay.pay_livewire'; }
    public function processPaymentViewData(array $data): array
    {
        return array_merge($data, ['gateway' => $this, 'payment_hash' => $this->payment_hash->hash,
            'amount' => $this->payment_hash->amount_with_fee(), 'currency' => $this->client->getCurrencyCode(), 'payment_method_id' => GatewayType::CRYPTO]);
    }
    public function processPaymentResponse($request)
    {
        if ($this->client->getCurrencyCode() !== 'USD') throw new PaymentFailed('MakePay requires USD invoices. Other fiat currencies are not converted automatically.');
        $hash = $this->payment_hash->hash;
        $saved = DB::transaction(function () use ($hash) {
            $row = PaymentHash::where('hash', $hash)->lockForUpdate()->firstOrFail();
            $state = (array) ($row->data->makepay ?? []);
            if ($state) {
                if (($state['client_id'] ?? null) !== $this->client->id || ($state['gateway_id'] ?? null) !== $this->company_gateway->id) throw new PaymentFailed('Checkout belongs to a different account.');
                if (isset($state['url'])) return $state;
                throw new PaymentFailed('Checkout creation is pending. Contact the merchant before retrying.');
            }
            $state = ['client_id' => $this->client->id, 'gateway_id' => $this->company_gateway->id,
                'amount' => Protocol::amount($row->amount_with_fee()), 'state' => 'creating'];
            $row->data = array_merge((array) $row->data, ['makepay' => $state]); $row->save();
            return $state;
        });
        if (isset($saved['url'])) return redirect()->away($saved['url']);
        $invoice = $this->payment_hash->invoices()[0] ?? null;
        if (!$invoice) throw new PaymentFailed('Invoice reference missing.');
        $return = route('client.invoice.show', ['invoice' => $invoice->invoice_id]);
        $result = $this->api('POST', ['source' => 'invoice-ninja', 'status' => 'active', 'sendPaymentRequestEmail' => false,
            'payload' => ['title' => 'Invoice '.$invoice->invoice_number, 'amount' => $saved['amount'], 'currency' => 'USD',
                'orderId' => 'ninja_'.$hash, 'clientEmail' => $this->client->present()->email(),
                'returnUrl' => $return, 'successUrl' => $return, 'failureUrl' => $return, 'expirationTime' => '12h']]);
        $link = $result['paymentLink'] ?? [];
        $url = $link['publicUrl'] ?? '';
        if (empty($link['uid']) || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null) throw new PaymentFailed('MakePay returned an invalid hosted checkout.');
        $saved = array_merge($saved, ['uid' => $link['uid'], 'url' => $url, 'state' => 'pending']);
        DB::transaction(function () use ($hash, $saved) {
            $row = PaymentHash::where('hash', $hash)->lockForUpdate()->firstOrFail();
            $row->data = array_merge((array) $row->data, ['makepay' => $saved]); $row->save();
        });
        return redirect()->away($url);
    }
    public function processWebhookRequest($request = null)
    {
        $request = $request ?? request();
        $raw = $request->getContent();
        if (strlen($raw) > 262144) return response()->json(['error' => 'Payload too large'], 413);
        if (!Protocol::verify($raw, (string) $request->header('X-MakePay-Signature'), (string) $this->company_gateway->getConfigField('webhookSecret'))) return response()->json(['error' => 'Invalid signature'], 401);
        try { $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return response()->json(['error' => 'Invalid JSON'], 400); }
        if (($event['session']['status'] ?? '') !== 'complete') return response()->json(['status' => 'ignored']);
        $order = $event['paymentLink']['merchantOrderId'] ?? '';
        if (!is_string($order) || !str_starts_with($order, 'ninja_')) return response()->json(['error' => 'Invalid reference'], 400);
        $hash = substr($order, 6);
        try {
            return DB::transaction(function () use ($hash, $event) {
                $row = PaymentHash::where('hash', $hash)->lockForUpdate()->first();
                $saved = (array) ($row?->data->makepay ?? []);
                if (!$row || ($saved['gateway_id'] ?? null) !== $this->company_gateway->id || !Protocol::matches($event, $saved, $hash)) return response()->json(['error' => 'Checkout mismatch'], 400);
                $this->client = Client::where('id', $saved['client_id'])->where('company_id', $this->company_gateway->company_id)->firstOrFail();
                $this->payment_hash = $row;
                if ($row->payment_id) return response()->json(['status' => 'already_completed']);
                $this->createPayment(['payment_method' => GatewayType::CRYPTO, 'payment_type' => PaymentType::CRYPTO,
                    'amount' => $saved['amount'], 'transaction_reference' => $saved['uid'], 'gateway_type_id' => GatewayType::CRYPTO], Payment::STATUS_COMPLETED);
                return response()->json(['status' => 'completed']);
            });
        } catch (\Throwable $error) {
            report($error); return response()->json(['error' => 'Temporary payment processing failure'], 503);
        }
    }
    public function refund(Payment $payment, $refund_amount, $return_client_response = false) { throw new PaymentFailed('Issue the refund in MakePay and record it in Invoice Ninja after settlement.'); }
}

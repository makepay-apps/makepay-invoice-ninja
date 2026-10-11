<div style="padding:24px;max-width:560px;margin:auto">
    <p style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#7758d6">MakePay · Crypto checkout</p>
    <h2 style="font-size:24px;font-weight:600;margin:12px 0">Pay your invoice with crypto</h2>
    <p style="color:#64748b;margin-bottom:24px">Choose your currency and network on secure MakePay checkout. Your invoice updates after payment confirmation.</p>
    @include('portal.ninja2020.gateways.includes.payment_details')
        <form action="{{ route('client.payments.response') }}" method="post" id="makepay-checkout">
            @csrf
            <input type="hidden" name="company_gateway_id" value="{{ $gateway->getCompanyGatewayId() }}">
            <input type="hidden" name="payment_method_id" value="{{ $payment_method_id }}">
            <input type="hidden" name="payment_hash" value="{{ $payment_hash }}">
            <button type="submit" style="background:#7758d6;color:white;padding:14px 24px;border-radius:8px;width:100%;font-weight:600">Continue to MakePay</button>
        </form>
        <p style="font-size:12px;color:#64748b;margin-top:16px">Returning to this invoice does not confirm payment. Keep your transaction reference until the invoice is paid.</p>
</div>

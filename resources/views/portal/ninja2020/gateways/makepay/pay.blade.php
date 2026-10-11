@extends('portal.ninja2020.layout.payments', ['gateway_title' => 'MakePay', 'card_title' => 'Crypto payment'])
@section('gateway_content')
    @include('portal.ninja2020.gateways.makepay.content')
@endsection

@extends('layout')
@section('title','订单 #'.$order->id)
@section('content')<h1>订单 #{{ $order->id }}</h1><section class="panel"><p>{{ $order->status }} · {{ $order->created_at }}</p><ul>@foreach($items as $i)<li>{{ $i->name }}　{{ $i->quantity }} × RM {{ $i->price }}</li>@endforeach</ul><h2>RM {{ $order->total }}</h2><a href="/orders">{{ __('messages.order_list') }}</a></section>@endsection
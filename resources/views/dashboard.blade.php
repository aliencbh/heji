@extends('layout')
@section('title', __('messages.dashboard'))
@section('content')
<h1>{{ __(config('heji.sites.'.request()->attributes->get('site'))) }}{{ __('messages.dashboard') }}</h1><p class="lead">{{ auth()->user()->name }}{{ __('messages.choose_business') }}</p><div class="cards">
@foreach(config('nav.'.request()->attributes->get('site')) as $group=>$links)@foreach($links as $perm=>$link)@can('page',$perm)<a class="card" href="{{ $link[1] }}"><small>{{ __($group) }}</small><h2>{{ $link[0] }} ↗</h2></a>@endcan @endforeach @endforeach
@if(request()->attributes->get('site')==='shop')<a class="card" href="/"><small>{{ __('messages.frontend') }}</small><h2>{{ __('messages.nav_shop_products') }} ↗</h2></a>@endif
@can('developer')<a class="card" href="/admin/roles"><small>{{ __('messages.nav_developer') }}</small><h2>{{ __('messages.nav_roles') }} ↗</h2></a>@endcan
</div>
@if(!auth()->user()->developer && !collect(config('heji.groups.'.request()->attributes->get('site')))->flatMap(fn($v)=>array_keys($v))->contains(fn($p)=>auth()->user()->can('page',$p)))<p class="notice">{{ __('messages.no_permission') }}</p>@endif
@endsection
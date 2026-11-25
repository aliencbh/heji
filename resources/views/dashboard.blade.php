@extends('layout')
@section('title','工作台')
@section('content')
<h1>{{ config('heji.sites.'.request()->attributes->get('site')) }}工作台</h1><p class="lead">{{ auth()->user()->name }}，请选择要处理的业务。</p><div class="cards">
@foreach(config('nav.'.request()->attributes->get('site')) as $group=>$links)@foreach($links as $perm=>$link)@can('page',$perm)<a class="card" href="{{ $link[1] }}"><small>{{ $group }}</small><h2>{{ $link[0] }} ↗</h2></a>@endcan @endforeach @endforeach
@if(request()->attributes->get('site')==='shop')<a class="card" href="/"><small>前台</small><h2>选购商品 ↗</h2></a>@endif
@can('developer')<a class="card" href="/admin/roles"><small>开发者</small><h2>角色与权限 ↗</h2></a>@endcan
</div>
@if(!auth()->user()->developer && !collect(config('heji.groups.'.request()->attributes->get('site')))->flatMap(fn($v)=>array_keys($v))->contains(fn($p)=>auth()->user()->can('page',$p)))<p class="notice">此账号尚无本站后台业务权限，请联系开发员分配角色。</p>@endif
@endsection
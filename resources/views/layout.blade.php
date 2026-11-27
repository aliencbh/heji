<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title','工作台') · 合集</title><link rel="stylesheet" href="/assets/app.css">@stack('styles')<script defer src="/assets/app.js"></script></head>
@php($site=request()->attributes->get('site'))
<body class="site-{{ $site }}"><header><div class="mast"><a class="brand" href="/"><span class="seal">合</span>合集<span class="line"></span><span>{{ config('heji.sites.'.$site) }}</span></a><nav>@include('partials.translate-button')<a href="/admin">{{ __('messages.dashboard') }}</a>@auth<form method="post" action="/logout">@csrf<button class="quiet">{{ __('messages.logout') }}</button></form>@else<a href="/login">{{ __('messages.login') }}</a>@endauth</nav></div></header>
<div class="frame">
@auth<aside><a href="/admin" class="section-home">后台总览</a>
@foreach(config('nav.'.$site) as $group=>$links)
@if(collect($links)->keys()->contains(fn($p)=>auth()->user()->can('page',$p)))
<details open><summary>{{ $group }}</summary>@foreach($links as $perm=>$link)@can('page',$perm)<a href="{{ $link[1] }}" @class(['current'=>request()->getPathInfo()===$link[1]])>{{ $link[0] }}</a>@endcan @endforeach</details>
@endif @endforeach
@if($site==='shop')<details open><summary>我的店铺</summary><a href="/">选购商品</a><a href="/orders">我的订单</a></details>@endif
@can('developer')<details open><summary>开发者</summary><details open><summary>页面</summary>
@foreach(['roles'=>'角色与权限','dev/audit'=>'数据库日志','dev/logs/errors'=>'错误日志','dev/logs/tests'=>'测试日志','dev/backups'=>'数据库备份','dev/status'=>'服务器状态'] as $path=>$label)<a href="/admin/{{ $path }}">{{ $label }}</a>@endforeach
</details></details>@endcan</aside>@endauth
<main><div class="crumb"><a href="/">合集{{ config('heji.sites.'.$site) }}</a><span>／</span>@if(request()->is('admin/*'))<a href="/admin">后台</a><span>／</span>@endif @yield('title','工作台')</div>
@if(session('ok'))<div class="notice" role="status">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="errors" role="alert"><strong>请检查以下内容</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')</main></div></body></html>
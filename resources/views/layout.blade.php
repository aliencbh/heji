<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="csrf-token" content="{{ csrf_token() }}"><title>@yield('title', __('messages.dashboard')) · {{ __('messages.site_name') }}</title><link rel="stylesheet" href="/assets/app.css">@stack('styles')<script defer src="/assets/app.js"></script></head>
@php($site=request()->attributes->get('site'))
<body class="site-{{ $site }}"><header><div class="mast"><a class="brand" href="/"><span class="seal">{{ __('messages.site_seal') }}</span>{{ __('messages.site_name') }}<span class="line"></span><span>{{ config('heji.sites.'.$site) }}</span></a><nav>@include('partials.translate-button')<a href="/admin">{{ __('messages.dashboard') }}</a>@auth<form method="post" action="/logout">@csrf<button class="quiet">{{ __('messages.logout') }}</button></form>@else<a href="/login">{{ __('messages.login') }}</a>@endauth</nav></div></header>
<div class="frame">
@auth<aside><a href="/admin" class="section-home">{{ __('messages.nav_overview') }}</a>
@foreach(config('nav.'.$site) as $group=>$links)
@if(collect($links)->keys()->contains(fn($p)=>auth()->user()->can('page',$p)))
<details open><summary>{{ $group }}</summary>@foreach($links as $perm=>$link)@can('page',$perm)<a href="{{ $link[1] }}" @class(['current'=>request()->getPathInfo()===$link[1]])>{{ $link[0] }}</a>@endcan @endforeach</details>
@endif @endforeach
@if($site==='shop')<details open><summary>{{ __('messages.nav_my_shop') }}</summary><a href="/">{{ __('messages.nav_shop_products') }}</a><a href="/orders">{{ __('messages.nav_my_orders') }}</a></details>@endif
@can('developer')<details open><summary>{{ __('messages.nav_developer') }}</summary><details open><summary>{{ __('messages.nav_pages') }}</summary>
@foreach(['roles'=>__('messages.nav_roles'),'dev/audit'=>__('messages.nav_db_logs'),'dev/logs/errors'=>__('messages.nav_error_logs'),'dev/logs/tests'=>__('messages.nav_test_logs'),'dev/backups'=>__('messages.nav_backups'),'dev/status'=>__('messages.nav_server_status')] as $path=>$label)<a href="/admin/{{ $path }}">{{ $label }}</a>@endforeach
</details></details>@endcan</aside>@endauth
<main><div class="crumb"><a href="/">{{ __('messages.site_name') }}{{ config('heji.sites.'.$site) }}</a><span>／</span>@if(request()->is('admin/*'))<a href="/admin">{{ __('messages.nav_backend') }}</a><span>／</span>@endif @yield('title', __('messages.dashboard'))</div>
@if(session('ok'))<div class="notice" role="status">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="errors" role="alert"><strong>{{ __('messages.nav_check') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')</main></div>@include('partials.password-eye')</body></html>
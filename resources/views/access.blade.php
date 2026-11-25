@extends('layout')
@section('title',$register?'注册账号':'账号登录')
@section('content')
<section class="auth panel"><h1>{{ $register?'注册账号':'欢迎回来' }}</h1><form method="post" class="stack">@csrf
@if($register)<label>姓名<input name="name" required maxlength="100" autocomplete="name" value="{{ old('name') }}"></label>@endif
<label>邮箱<input name="email" type="email" required maxlength="254" autocomplete="email" value="{{ old('email') }}"></label>
<label>密码<input name="password" type="password" required autocomplete="{{ $register?'new-password':'current-password' }}" @if($register) minlength="12" maxlength="72" @endif></label>
@if($register)<small>至少 12 个字符，包含字母和数字。</small><label>确认密码<input type="password" name="password_confirmation" required autocomplete="new-password"></label>@endif
<button>{{ $register?'创建账号':'登录' }}</button><a href="{{ $register?'/login':'/register' }}">{{ $register?'已有账号，前往登录':'注册新账号' }}</a></form></section>
@endsection
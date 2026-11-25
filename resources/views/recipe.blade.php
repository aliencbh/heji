@extends('layout')
@section('title',$recipe->name)
@section('content')
<h1>{{ $recipe->name }}</h1><p class="lead">{{ $recipe->kind }} · {{ $recipe->family }} · {{ $recipe->tasty?'好吃':'不好吃' }} · {{ $recipe->cooked?'已经煮过':'还没煮过' }}</p>
<section class="panel"><h2>准备材料与注意事项</h2><ul>@foreach($items as $i)<li>{{ $i->name }}　{{ $i->quantity }} {{ $i->unit }}　{{ $i->notes }}</li>@endforeach</ul><h2>煮法</h2><p class="pre">{{ $recipe->method }}</p>@can('page','recipes.edit')
<form class="stack" method="post" action="/admin/dish/{{ $recipe->id }}/adjustment">
    @csrf @method('PATCH')
    <label>需要调整<textarea name="adjustment" rows="3" maxlength="3000">{{ is_string(old('adjustment')) ? old('adjustment') : $recipe->adjustment }}</textarea></label>
    <div><button>保存调整记录</button></div>
</form>
@else
<h2>需要调整</h2><p class="pre">{{ $recipe->adjustment ?? '暂无记录' }}</p>
@endcan
@can('page','recipes.edit')<a class="btn" href="/admin/recipes/{{ $recipe->id }}/edit">编辑食谱</a>@endcan</section>
@endsection
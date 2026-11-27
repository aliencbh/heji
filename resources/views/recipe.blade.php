@extends('layout')
@section('title',$recipe->name)
@section('content')
<h1>{{ $recipe->name }}</h1><p class="lead">{{ $recipe->kind }} · {{ $recipe->family }} · {{ $recipe->tasty?__('messages.tasty'):__('messages.not_tasty') }} · {{ $recipe->cooked?__('messages.cooked'):__('messages.not_cooked_yet') }}</p>
<section class="panel"><h2>{{ __('messages.prepare_ingredients_notes') }}</h2><ul>@foreach($items as $i)<li>{{ $i->name }}　{{ $i->quantity }} {{ $i->unit }}　{{ $i->notes }}</li>@endforeach</ul><h2>{{ __('messages.method') }}</h2><p class="pre">{{ $recipe->method }}</p>@can('page','recipes.edit')
<form class="stack" method="post" action="/admin/dish/{{ $recipe->id }}/adjustment">
    @csrf @method('PATCH')
    <label>{{ __('messages.need_adjust') }}<textarea name="adjustment" rows="3" maxlength="3000">{{ is_string(old('adjustment')) ? old('adjustment') : $recipe->adjustment }}</textarea></label>
    <div><button>{{ __('messages.save_adjust_record') }}</button></div>
</form>
@else
<h2>{{ __('messages.need_adjust') }}</h2><p class="pre">{{ $recipe->adjustment ?? __('messages.no_record') }}</p>
@endcan
@can('page','recipes.edit')<a class="btn" href="/admin/recipes/{{ $recipe->id }}/edit">{{ __('messages.edit_recipe') }}</a>@endcan</section>
@endsection
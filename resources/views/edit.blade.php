@extends('layout')
@section('title',($row?__('messages.edit'):__('messages.add_new_short')).(['products'=>__('messages.nav_products'),'ingredients'=>__('messages.nav_ingredients'),'recipes'=>__('messages.nav_recipe_list')][$resource]))
@section('content')
<h1>@yield('title')</h1><form class="panel formgrid" method="post" enctype="multipart/form-data" action="/admin/{{ $resource }}/{{ $row->id??'' }}">@csrf
@foreach($fields as $key=>$label)
@php($value=old($key,$row->$key??(in_array($key,['active','vacuum','tasty'])?1:0)))
<label>{{ $label }}
@if(in_array($key,['weekly','storable','marinate','vacuum','tasty','cooked','active']))
<select name="{{ $key }}"><option value="1" @selected((bool)$value)>{{ __('messages.yes') }}</option><option value="0" @selected(!(bool)$value)>{{ __('messages.no') }}</option></select>
@elseif(in_array($key,['storage','kind','family']))
@php($choices=match($key){'storage'=>[__('messages.storage_vent'),__('messages.storage_fridge'),__('messages.storage_freezer')],'kind'=>\App\Services\MenuSolver::SLOTS,'family'=>array_merge(config('heji.meats'),config('heji.vegetables'),[__('messages.soup')],config('heji.extras'))})
<select required name="{{ $key }}">@foreach($choices as $opt)<option @selected($value===$opt)>{{ $opt }}</option>@endforeach</select>
@elseif(in_array($key,['notes','method','adjustment','description']))<textarea name="{{ $key }}" rows="4" @required($key==='method')>{{ old($key,$row->$key??'') }}</textarea>
@elseif(in_array($key,['price','reserve','remaining']))<input required name="{{ $key }}" type="number" step="{{ $key==='price'?'0.01':'0.001' }}" min="0" value="{{ $value }}">
@else<input name="{{ $key }}" required value="{{ old($key,$row->$key??'') }}">
@endif</label>@endforeach
@if($resource==='products')<label>{{ __('messages.product_photo_hint') }}<input type="file" name="photo" accept="image/png,image/jpeg"></label>@endif
@if($resource==='recipes')<fieldset class="wide"><legend>{{ __('messages.prepare_ingredients') }}</legend><small>{{ __('messages.fill_ingredients_hint') }}</small><div class="formgrid">@forelse($items as $i)<label>{{ $i->name }} · {{ $i->unit }}<input type="number" min="0.001" step="0.001" name="amount[{{ $i->id }}]" value="{{ old('amount.'.$i->id,$quantities[$i->id]??'') }}">@if($i->notes)<small>{{ $i->notes }}</small>@endif</label>@empty<p>{{ __('messages.create_ingredients_first') }}</p>@endforelse</div></fieldset>@endif
<div class="wide actions"><button>{{ __('messages.save') }}</button><a href="/admin/{{ $resource }}">{{ __('messages.back_to_list') }}</a></div></form>
@endsection
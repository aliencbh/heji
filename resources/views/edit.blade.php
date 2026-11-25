@extends('layout')
@section('title',($row?'编辑':'新增').(['products'=>'商品','ingredients'=>'食材','recipes'=>'食谱'][$resource]))
@section('content')
<h1>@yield('title')</h1><form class="panel formgrid" method="post" enctype="multipart/form-data" action="/admin/{{ $resource }}/{{ $row->id??'' }}">@csrf
@foreach($fields as $key=>$label)
@php($value=old($key,$row->$key??(in_array($key,['active','vacuum','tasty'])?1:0)))
<label>{{ $label }}
@if(in_array($key,['weekly','storable','marinate','vacuum','tasty','cooked','active']))
<select name="{{ $key }}"><option value="1" @selected((bool)$value)>是</option><option value="0" @selected(!(bool)$value)>否</option></select>
@elseif(in_array($key,['storage','kind','family']))
@php($choices=match($key){'storage'=>['通风处','冷藏','冷冻'],'kind'=>\App\Services\MenuSolver::SLOTS,'family'=>array_merge(config('heji.meats'),config('heji.vegetables'),['汤'],config('heji.extras'))})
<select required name="{{ $key }}">@foreach($choices as $opt)<option @selected($value===$opt)>{{ $opt }}</option>@endforeach</select>
@elseif(in_array($key,['notes','method','adjustment','description']))<textarea name="{{ $key }}" rows="4" @required($key==='method')>{{ old($key,$row->$key??'') }}</textarea>
@elseif(in_array($key,['price','reserve','remaining']))<input required name="{{ $key }}" type="number" step="{{ $key==='price'?'0.01':'0.001' }}" min="0" value="{{ $value }}">
@else<input name="{{ $key }}" required value="{{ old($key,$row->$key??'') }}">
@endif</label>@endforeach
@if($resource==='products')<label>商品照片（JPG / PNG，最大 2 MB）<input type="file" name="photo" accept="image/png,image/jpeg"></label>@endif
@if($resource==='recipes')<fieldset class="wide"><legend>准备材料</legend><small>填写所用食材的数量，未使用的食材留空。</small><div class="formgrid">@forelse($items as $i)<label>{{ $i->name }} · {{ $i->unit }}<input type="number" min="0.001" step="0.001" name="amount[{{ $i->id }}]" value="{{ old('amount.'.$i->id,$quantities[$i->id]??'') }}">@if($i->notes)<small>{{ $i->notes }}</small>@endif</label>@empty<p>请先建立食材。</p>@endforelse</div></fieldset>@endif
<div class="wide actions"><button>保存</button><a href="/admin/{{ $resource }}">返回列表</a></div></form>
@endsection
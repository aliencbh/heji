@extends('layout')
@section('title','饮食日历')
@push('styles')
<link rel="stylesheet" href="/assets/calendar.css">
@endpush
@section('content')
@php($month=$mode==='month')
@php($weekdays=['','一','二','三','四','五','六','日'])
@php($canEdit=auth()->user()->can('page','calendar.edit'))
<div class="heading">
    <div>
        <h1>饮食日历</h1>
        <p class="lead">{{ $month ? $start->format('Y年n月') : $start->format('Y.m.d').' — '.$end->format('Y.m.d') }}</p>
    </div>
    <nav class="cal-switch" aria-label="日历视图">
        @foreach(['month'=>'月视图','week'=>'周视图'] as $value=>$label)
        <a href="?view={{ $value }}&amp;date={{ $focus->toDateString() }}" @if($mode===$value) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
</div>
<div class="toolbar cal-tools">
    <nav aria-label="日期导航">
        <a class="btn alt" rel="prev" href="?view={{ $mode }}&amp;date={{ $previous->toDateString() }}">{{ $month?'上个月':'上一周' }}</a>
        <a class="btn alt" href="?view={{ $mode }}">{{ $month?'本月':'本周' }}</a>
        <a class="btn alt" rel="next" href="?view={{ $mode }}&amp;date={{ $next->toDateString() }}">{{ $month?'下个月':'下一周' }}</a>
    </nav>
    @if($canEdit && $generateFrom->lessThanOrEqualTo($end))
    <form method="post" action="/admin/calendar/generate" data-confirm="重新安排 {{ $generateFrom->toDateString() }} 至 {{ $end->toDateString() }} 的菜式？">
        @csrf
        <input type="hidden" name="view" value="{{ $mode }}">
        <input type="hidden" name="day" value="{{ $generateFrom->toDateString() }}">
        <input type="hidden" name="length" value="{{ (int)$generateFrom->diffInDays($end)+1 }}">
        <button>重新排版</button>
    </form>
    @endif
    @if($canEdit)
    <details><summary>连续排期</summary>
        <form method="post" class="stack" action="/admin/calendar/generate" data-confirm="重新安排所选日期范围内的菜式？">
            @csrf
            <input type="hidden" name="view" value="{{ $mode }}">
            <label>起始日期<input required type="date" name="day" min="{{ $tomorrow->toDateString() }}" value="{{ is_string(old('day')) ? old('day') : $generateFrom->toDateString() }}"></label>
            <label>连续天数<input required type="number" name="length" min="1" step="1" value="{{ is_scalar(old('length')) ? old('length') : 7 }}"></label>
            <button>生成排期</button>
        </form>
    </details>
    @endif
    @can('page','calendar.settings')
    <details><summary>星期设置</summary>
        <form method="post" action="/admin/calendar/settings" class="stack">@csrf
            <label>每周第一天<select name="first">@for($i=1;$i<=7;$i++)<option value="{{ $i }}" @selected($i===$first)>星期{{ $weekdays[$i] }}</option>@endfor</select></label>
            <fieldset><legend>周末</legend>@for($i=1;$i<=7;$i++)<label><input type="checkbox" name="weekend[]" value="{{ $i }}" @checked(in_array($i,$weekend,true))>周{{ $weekdays[$i] }}</label>@endfor</fieldset>
            <button>保存设置</button>
        </form>
    </details>
    @endcan
</div>
@if($canEdit)<p class="muted">拖动菜式到另一日期，或依次点选两道菜进行交换。</p>@endif
@if($month)
<nav class="cal-index" aria-label="跳转到本月某一天">
    @for($i=0;$i<7;$i++)<span>周{{ $weekdays[($first-1+$i)%7+1] }}</span>@endfor
    @for($i=0;$i<$leading;$i++)<span aria-hidden="true"></span>@endfor
    @for($i=0;$i<$dayCount;$i++)
        @php($date=$start->addDays($i))
        <a href="#day-{{ $date->toDateString() }}" aria-label="{{ $date->format('n月j日') }}" @if($date->isSameDay($today)) aria-current="date" @endif>{{ $date->day }}</a>
    @endfor
</nav>
@endif
<div class="calendar calendar-{{ $mode }}" data-start="{{ $start->toDateString() }}" data-end="{{ $end->toDateString() }}">
    @for($i=0;$i<7;$i++)<div class="cal-weekday" aria-hidden="true">星期{{ $weekdays[($first-1+$i)%7+1] }}</div>@endfor
    @for($i=0;$i<$leading;$i++)<div class="cal-blank" aria-hidden="true"></div>@endfor
    @for($i=0;$i<$dayCount;$i++)
        @php($date=$start->addDays($i))
        @php($day=$date->toDateString())
        <section id="day-{{ $day }}" aria-label="{{ $date->format('n月j日') }}" @class(['day','today'=>$date->isSameDay($today),'cal-weekend'=>in_array($date->isoWeekday(),$weekend,true)])>
            <div class="dayhead"><b><time datetime="{{ $day }}">{{ $date->format('d') }}</time></b><span>周{{ $weekdays[$date->isoWeekday()] }}{{ $date->isSameDay($today)?' · 今天':'' }}</span></div>
            @if(isset($origins[$day]))<small>原定 {{ $origins[$day] }}</small>@endif
            @if(in_array($day,$skips,true))
                <em>今天没煮</em>
            @else
                @forelse($rows[$day]??[] as $meal)
                    @if($canEdit && $date->greaterThanOrEqualTo($today))
                    <button type="button" class="meal" data-slot="{{ $meal->slot }}" draggable="true" data-meal="{{ $meal->id }}"><small>{{ $meal->slot }}</small>{{ $meal->name }}</button>
                    @else
                    <div class="meal" data-slot="{{ $meal->slot }}"><small>{{ $meal->slot }}</small>{{ $meal->name }}</div>
                    @endif
                @empty
                    <small>尚未安排</small>
                @endforelse
                @if(isset($thaw[$day]))<p class="thaw">需解冻 · {{ $thaw[$day]->pluck('name')->implode('、') }}</p>@endif
                @if($canEdit && $date->isSameDay($today))
                <form method="post" action="/admin/calendar/skip" data-confirm="将今天的整份菜单移到明天，并把后续每份菜单推迟一天？">
                    @csrf<input type="hidden" name="day" value="{{ $day }}"><button class="alt">今天没煮</button>
                </form>
                @endif
            @endif
        </section>
    @endfor
</div>
@if($canEdit)<form id="swap" method="post" action="/admin/calendar/swap" hidden>@csrf<input name="from"><input name="to"></form>@endif
@endsection

@extends('layout')
@section('title', __('messages.nav_calendar'))
@push('styles')
<link rel="stylesheet" href="/assets/calendar.css">
@endpush
@section('content')
@php($month=$mode==='month')
@php($weekdays=['', __('messages.week_mon'), __('messages.week_tue'), __('messages.week_wed'), __('messages.week_thu'), __('messages.week_fri'), __('messages.week_sat'), __('messages.week_sun')])
@php($canEdit=auth()->user()->can('page','calendar.edit'))
<div class="heading">
    <div>
        <h1>{{ __('messages.nav_calendar') }}</h1>
        <p class="lead">{{ $month ? $start->format(__('messages.year_month')) : $start->format('Y.m.d').' — '.$end->format('Y.m.d') }}</p>
    </div>
    <nav class="cal-switch" aria-label="{{ __('messages.calendar_view') }}">
        @foreach(['month'=>__('messages.month_view'),'week'=>__('messages.week_view')] as $value=>$label)
        <a href="?view={{ $value }}&amp;date={{ $focus->toDateString() }}" @if($mode===$value) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
</div>
<div class="toolbar cal-tools">
    <nav aria-label="{{ __('messages.date_nav') }}">
        <a class="btn alt" rel="prev" href="?view={{ $mode }}&amp;date={{ $previous->toDateString() }}">{{ $month?__('messages.prev_month'):__('messages.prev_week') }}</a>
        <a class="btn alt" href="?view={{ $mode }}">{{ $month?__('messages.this_month'):__('messages.this_week') }}</a>
        <a class="btn alt" rel="next" href="?view={{ $mode }}&amp;date={{ $next->toDateString() }}">{{ $month?__('messages.next_month'):__('messages.next_week') }}</a>
    </nav>
    @if($canEdit && $generateFrom->lessThanOrEqualTo($end))
    <form method="post" action="/admin/calendar/generate" data-confirm="{{ __('messages.rearrange') }} {{ $generateFrom->toDateString() }} {{ __('messages.to') }} {{ $end->toDateString() }} {{ __('messages.dishes') }}?">
        @csrf
        <input type="hidden" name="view" value="{{ $mode }}">
        <input type="hidden" name="day" value="{{ $generateFrom->toDateString() }}">
        <input type="hidden" name="length" value="{{ (int)$generateFrom->diffInDays($end)+1 }}">
        <button>{{ __('messages.rearrange') }}</button>
    </form>
    @endif
    @if($canEdit)
    <details><summary>{{ __('messages.continuous_schedule') }}</summary>
        <form method="post" class="stack" action="/admin/calendar/generate" data-confirm="{{ __('messages.rearrange_range_confirm') }}">
            @csrf
            <input type="hidden" name="view" value="{{ $mode }}">
            <label>{{ __('messages.start_date') }}<input required type="date" name="day" min="{{ $tomorrow->toDateString() }}" value="{{ is_string(old('day')) ? old('day') : $generateFrom->toDateString() }}"></label>
            <label>{{ __('messages.continuous_days') }}<input required type="number" name="length" min="1" step="1" value="{{ is_scalar(old('length')) ? old('length') : 7 }}"></label>
            <button>{{ __('messages.generate_schedule') }}</button>
        </form>
    </details>
    @endif
    @can('page','calendar.settings')
    <details><summary>{{ __('messages.week_settings') }}</summary>
        <form method="post" action="/admin/calendar/settings" class="stack">@csrf
            <label>{{ __('messages.first_day') }}<select name="first">@for($i=1;$i<=7;$i++)<option value="{{ $i }}" @selected($i===$first)>{{ __('messages.week_full_prefix') }}{{ $weekdays[$i] }}</option>@endfor</select></label>
            <fieldset><legend>{{ __('messages.weekend') }}</legend>@for($i=1;$i<=7;$i++)<label><input type="checkbox" name="weekend[]" value="{{ $i }}" @checked(in_array($i,$weekend,true))>{{ __('messages.week_prefix') }}{{ $weekdays[$i] }}</label>@endfor</fieldset>
            <button>{{ __('messages.save_settings') }}</button>
        </form>
    </details>
    @endcan
</div>
@if($canEdit)<p class="muted">{{ __('messages.drag_hint') }}</p>@endif
@if($month)
<nav class="cal-index" aria-label="{{ __('messages.jump_to_day') }}">
    @for($i=0;$i<7;$i++)<span>{{ __('messages.week_prefix') }}{{ $weekdays[($first-1+$i)%7+1] }}</span>@endfor
    @for($i=0;$i<$leading;$i++)<span aria-hidden="true"></span>@endfor
    @for($i=0;$i<$dayCount;$i++)
        @php($date=$start->addDays($i))
        <a href="#day-{{ $date->toDateString() }}" aria-label="{{ $date->format(__('messages.month_day')) }}" @if($date->isSameDay($today)) aria-current="date" @endif>{{ $date->day }}</a>
    @endfor
</nav>
@endif
<div class="calendar calendar-{{ $mode }}" data-start="{{ $start->toDateString() }}" data-end="{{ $end->toDateString() }}">
    @for($i=0;$i<7;$i++)<div class="cal-weekday" aria-hidden="true">{{ __('messages.week_full_prefix') }}{{ $weekdays[($first-1+$i)%7+1] }}</div>@endfor
    @for($i=0;$i<$leading;$i++)<div class="cal-blank" aria-hidden="true"></div>@endfor
    @for($i=0;$i<$dayCount;$i++)
        @php($date=$start->addDays($i))
        @php($day=$date->toDateString())
        <section id="day-{{ $day }}" aria-label="{{ $date->format(__('messages.month_day')) }}" @class(['day','today'=>$date->isSameDay($today),'cal-weekend'=>in_array($date->isoWeekday(),$weekend,true)])>
            <div class="dayhead"><b><time datetime="{{ $day }}">{{ $date->format('d') }}</time></b><span>{{ __('messages.week_prefix') }}{{ $weekdays[$date->isoWeekday()] }}{{ $date->isSameDay($today)?__('messages.today_suffix'):'' }}</span></div>
            @if(isset($origins[$day]))<small>{{ __('messages.originally') }} {{ $origins[$day] }}</small>@endif
            @if(in_array($day,$skips,true))
                <em>{{ __('messages.not_cooked_today') }}</em>
            @else
                @forelse($rows[$day]??[] as $meal)
                    @if($canEdit && $date->greaterThanOrEqualTo($today))
                    <button type="button" class="meal" data-slot="{{ $meal->slot }}" draggable="true" data-meal="{{ $meal->id }}"><small>{{ $meal->slot }}</small>{{ $meal->name }}</button>
                    @else
                    <div class="meal" data-slot="{{ $meal->slot }}"><small>{{ $meal->slot }}</small>{{ $meal->name }}</div>
                    @endif
                @empty
                    <small>{{ __('messages.not_scheduled') }}</small>
                @endforelse
                @if(isset($thaw[$day]))<p class="thaw">{{ __('messages.need_thaw') }} · {{ $thaw[$day]->pluck('name')->implode('、') }}</p>@endif
                @if($canEdit && $date->isSameDay($today))
                <form method="post" action="/admin/calendar/skip" data-confirm="{{ __('messages.move_today_menu') }}">
                    @csrf<input type="hidden" name="day" value="{{ $day }}"><button class="alt">{{ __('messages.not_cooked_today') }}</button>
                </form>
                @endif
            @endif
        </section>
    @endfor
</div>
@if($canEdit)<form id="swap" method="post" action="/admin/calendar/swap" hidden>@csrf<input name="from"><input name="to"></form>@endif
@endsection

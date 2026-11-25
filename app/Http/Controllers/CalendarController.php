<?php
namespace App\Http\Controllers;
use App\Services\Calendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class CalendarController {
    public function index(Request $r,Calendar $calendar) {
        $data=$r->validate(['date'=>'nullable|date_format:Y-m-d','view'=>'nullable|in:month,week']);
        $today=CarbonImmutable::today(config('app.timezone'));
        $focus=isset($data['date']) ? CarbonImmutable::createFromFormat('!Y-m-d',$data['date']) : $today;
        $mode=$data['view']??'month';
        [$first,$weekend]=$calendar->prefs();
        if ($mode==='month') {
            $start=$focus->startOfMonth();
            $dayCount=$start->daysInMonth;
            $previous=$focus->subMonthNoOverflow();
            $next=$focus->addMonthNoOverflow();
        } else {
            $start=$focus->subDays(($focus->isoWeekday()-$first+7)%7);
            $dayCount=7;
            $previous=$focus->subWeek();
            $next=$focus->addWeek();
        }
        $end=$start->addDays($dayCount-1);
        $leading=($start->isoWeekday()-$first+7)%7;
        $tomorrow=$today->addDay();
        $generateFrom=$start->greaterThan($tomorrow) ? $start : $tomorrow;
        $rows=DB::table('meals as m')->join('recipes as r','r.id','=','m.recipe_id')->whereBetween('day',[$start->toDateString(),$end->toDateString()])
            ->select('m.*','r.name')->orderByRaw("array_position(ARRAY['荤菜','素菜','汤','周末其他'],slot)")->get()->groupBy('day');
        $thaw=DB::table('meals as m')->join('recipe_items as ri','ri.recipe_id','=','m.recipe_id')->join('ingredients as i','i.id','=','ri.ingredient_id')
            ->whereBetween('day',[$start->toDateString(),$end->toDateString()])->where('storage','冷冻')->select('day','i.name')->distinct()->get()->groupBy('day');
        $days=DB::table('meal_days')->whereBetween('day',[$start->toDateString(),$end->toDateString()])->get();
        $skips=$days->where('skipped',true)->pluck('day')->all();
        $origins=$days->whereNotNull('rule_day')->pluck('rule_day','day');
        return view('calendar',compact('mode','focus','today','tomorrow','start','end','dayCount','leading',
            'previous','next','generateFrom','rows','thaw','skips','origins','first','weekend'));
    }
    public function generate(Request $r,Calendar $calendar) {
        $d=$r->validate([
            'day'=>'required|date_format:Y-m-d|after_or_equal:'.CarbonImmutable::tomorrow(config('app.timezone'))->toDateString(),
            'length'=>'required|integer|min:1',
            'view'=>'sometimes|in:month,week',
        ],['day.after_or_equal'=>'排期最早从明天开始。']);
        $start=CarbonImmutable::parse($d['day']);
        $days=(int)$d['length'];
        if ($days > 1 + (int)$start->diffInDays(CarbonImmutable::create(9999,12,31,0,0))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['length'=>'结束日期超出四位年份日期格式。']);
        }
        $calendar->arrange($start,$days,'shuffle');
        $query=['date'=>$start->toDateString()];
        if (isset($d['view'])) $query['view']=$d['view'];
        return redirect('/admin/calendar?'.http_build_query($query))->with('ok','已更新 '.$start->toDateString().' 至 '.$start->addDays($days-1)->toDateString().' 的排期。');
    }
    public function skip(Request $r,Calendar $calendar) {
        $today=CarbonImmutable::today(config('app.timezone'));
        $r->validate(['day'=>'required|date_format:Y-m-d|in:'.$today->toDateString()]);
        $moved=$calendar->skipDay($today);
        return back()->with('ok',$moved?'今天已标记没煮，今天及之后的菜单已整体顺延一天。':'今天已标记没煮，本次没有再次顺延。');
    }
    public function swap(Request $r,Calendar $calendar) {
        $d=$r->validate(['from'=>'required|integer','to'=>'required|integer|different:from']); $calendar->swap($d['from'],$d['to']); return back()->with('ok','菜式已交换。');
    }
    public function settings(Request $r,Calendar $calendar) {
        $d=$r->validate(['first'=>'required|integer|between:1,7','weekend'=>'sometimes|array|max:7','weekend.*'=>'integer|distinct|between:1,7']);
        DB::transaction(function() use($d,$calendar) {
            Calendar::lock();
            [$oldFirst,$oldWeekend]=$calendar->prefs();
            $weekend=array_map('intval',$d['weekend']??[]);
            sort($weekend); sort($oldWeekend);
            if ($oldFirst===(int)$d['first'] && $oldWeekend===$weekend) return;
            DB::table('settings')->where('key','first_day')->update(['value'=>(string)$d['first']]);
            DB::table('settings')->where('key','weekend')->update(['value'=>json_encode($weekend)]);
            $first=CarbonImmutable::tomorrow(config('app.timezone')); $last=DB::table('meal_days')->where('day','>=',$first->toDateString())->max('day');
            if ($last) $calendar->arrange($first,1+(int)$first->diffInDays(CarbonImmutable::parse($last)),'settings');
        }); return back()->with('ok','日历规则已保存。');
    }
    public function restock(Calendar $calendar) {
        $start=$calendar->start(CarbonImmutable::today(config('app.timezone')))->addWeek(); $end=$start->addDays(6);
        $need=DB::table('meals as m')->join('recipe_items as r','r.recipe_id','=','m.recipe_id')->whereBetween('m.day',[$start->toDateString(),$end->toDateString()])
            ->groupBy('r.ingredient_id')->selectRaw('r.ingredient_id,sum(r.quantity) as needed');
        $items=DB::table('ingredients as i')->leftJoinSub($need,'n','i.id','=','n.ingredient_id')
            ->selectRaw('i.*,coalesce(needed,0) as needed,greatest(0,coalesce(needed,0)+CASE WHEN storable THEN reserve ELSE 0 END-remaining) as buy')
            ->where(fn($q)=>$q->whereNotNull('n.needed')->orWhere('i.weekly',true)->orWhere('i.storable',true))->orderByDesc('buy')->orderBy('name')->get();
        $days=DB::table('meal_days')->whereBetween('day',[$start->toDateString(),$end->toDateString()])->get();
        $counts=DB::table('meals')->whereBetween('day',[$start->toDateString(),$end->toDateString()])->selectRaw('day,count(*) as n')->groupBy('day')->pluck('n','day');
        [, $weekend]=$calendar->prefs();
        $ready=$days->filter(fn($d)=>$d->skipped || ($counts[$d->day]??0)===(in_array(CarbonImmutable::parse($d->rule_day??$d->day)->isoWeekday(),$weekend,true)?4:3))->count();
        return view('restock',compact('start','end','items','ready'));
    }
    public function remaining(Request $r) {
        $d=$r->validate(['id'=>'required|integer|exists:ingredients,id','remaining'=>'required|numeric|decimal:0,3|min:0|max:999999999.999']);
        DB::table('ingredients')->where('id',$d['id'])->update(['remaining'=>$d['remaining']]); return back()->with('ok','现存量已更新。');
    }
}
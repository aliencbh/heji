<?php
namespace App\Services;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class Calendar {
    private MenuSolver $solver;
    public function __construct(MenuSolver $solver) { $this->solver=$solver; }
    public static function lock(): void { DB::select('select pg_advisory_xact_lock(924377)'); }
    public function prefs(): array {
        $s=DB::table('settings')->pluck('value','key');
        return [(int)$s['first_day'],json_decode($s['weekend'],true,flags:JSON_THROW_ON_ERROR)];
    }
    public function start(CarbonImmutable $day): CarbonImmutable {
        [$first]=$this->prefs(); return $day->subDays(($day->isoWeekday()-$first+7)%7)->startOfDay();
    }
    public function pantry(): array {
        $items=DB::table('recipe_items')->get()->groupBy('recipe_id');
        return DB::table('recipes')->get()->mapWithKeys(fn($r)=>[$r->id=>['id'=>$r->id,'kind'=>$r->kind,'family'=>$r->family,'tasty'=>(bool)$r->tasty,'items'=>($items[$r->id]??collect())->pluck('ingredient_id')->all()]])->all();
    }
    public function adjacent(string $date): array {
        return DB::table('meals as m')->join('recipe_items as i','m.recipe_id','=','i.recipe_id')->where('m.day',$date)->distinct()->pluck('i.ingredient_id')->all();
    }
    public function skipDay(CarbonImmutable $date): bool {
        return DB::transaction(function () use ($date) {
            self::lock();
            $today = $date->toDateString();
            if (DB::table('meal_days')->where('day', $today)->value('skipped')) return false;

            // Move the last day first to leave each destination free. The FK moves its meals with it.
            $days = DB::table('meal_days')->where('day', '>=', $today)->select('id','day')->lazyByIdDesc(500,'day');
            foreach ($days as $day) {
                $tomorrow = CarbonImmutable::parse($day->day)->addDay()->toDateString();
                DB::update('UPDATE meal_days SET rule_day = COALESCE(rule_day, day), day = ? WHERE id = ?', [$tomorrow, $day->id]);
            }
            DB::table('meal_days')->insert(['day' => $today, 'skipped' => true]);
            return true;
        });
    }
    public function arrange(CarbonImmutable $begin,int $length,string $mode='fill'): void {
        if ($mode==='skip') {
            $this->skipDay($begin);
            return;
        }
        DB::transaction(function() use($begin,$length,$mode) {
            self::lock();
            [$first,$weekend]=$this->prefs();
            $end=$begin->addDays($length-1);
            $range=[$begin->toDateString(),$end->toDateString()];
            $entries=DB::table('meals')->whereBetween('day',$range)->get();
            $dayRows=DB::table('meal_days')->whereBetween('day',$range)->get()->keyBy('day');
            $skip=$dayRows->where('skipped',true)->pluck('day')->flip()->all();
            $ruleDays=$mode==='fill' ? $dayRows->whereNotNull('rule_day')->pluck('rule_day','day')->all() : [];
            $existing=[]; $prefer=[]; $dates=[];
            foreach ($entries as $row) {
                $existing[$row->day][$row->slot]=$row;
                if ($mode!=='shuffle') $prefer[$row->day][$row->slot]=$row->recipe_id;
            }
            for ($i=0;$i<$length;$i++) $dates[]=$begin->addDays($i)->toDateString();
            $plan=$this->solver->solve($dates,$this->pantry(),$first,$weekend,$prefer,$skip,
                $this->adjacent($begin->subDay()->toDateString()),$this->adjacent($end->addDay()->toDateString()),$ruleDays);
            foreach (array_chunk($dates, 250) as $batch) {
                $daysToSave = $mealsToSave = $obsoleteIds = [];
                foreach ($batch as $day) {
                    $origin = $ruleDays[$day] ?? null;
                    if (!isset($dayRows[$day]) || $dayRows[$day]->rule_day !== $origin) {
                        $daysToSave[] = ['day'=>$day, 'skipped'=>isset($skip[$day]), 'rule_day'=>$origin];
                    }
                    $wanted = $plan[$day];
                    foreach ($existing[$day] ?? [] as $slot => $row) {
                        if (!isset($wanted[$slot])) $obsoleteIds[] = $row->id;
                    }
                    foreach ($wanted as $slot => $id) {
                        if (($existing[$day][$slot]->recipe_id ?? null) !== $id) {
                            $mealsToSave[] = ['day'=>$day, 'slot'=>$slot, 'recipe_id'=>$id];
                        }
                    }
                }
                if ($daysToSave) DB::table('meal_days')->upsert($daysToSave, ['day'], ['skipped','rule_day']);
                if ($obsoleteIds) DB::table('meals')->whereIn('id',$obsoleteIds)->delete();
                if ($mealsToSave) DB::table('meals')->upsert($mealsToSave, ['day','slot'], ['recipe_id']);
            }
        });
    }
    public function swap(int $a,int $b): void {
        DB::transaction(function() use($a,$b) {
            self::lock(); [$first,$weekend]=$this->prefs(); $pool=$this->pantry();
            $left=DB::table('meals')->find($a); $right=DB::table('meals')->find($b);
            abort_unless($left && $right,404);
            $ruleDays=DB::table('meal_days')->whereIn('day',[$left->day,$right->day])->pluck('rule_day','day');
            foreach ([[$left,$right],[$right,$left]] as [$from,$to]) {
                $ruleDay=$ruleDays[$to->day]??$to->day;
                if ($to->day<today()->toDateString() || !in_array($to->slot,$this->solver->slots($ruleDay,$weekend),true) ||
                    !$this->solver->eligible($pool[$from->recipe_id],$to->slot,$ruleDay,$first))
                    throw ValidationException::withMessages(['swap'=>__('messages.swap_mismatch')]);
                DB::table('meals')->where('id',$to->id)->update(['recipe_id'=>$from->recipe_id]);
            }
            foreach ([$left->day,$right->day] as $day) foreach ([-1,1] as $offset) {
                if (array_intersect($this->adjacent($day),$this->adjacent(CarbonImmutable::parse($day)->addDays($offset)->toDateString())))
                    throw ValidationException::withMessages(['swap'=>__('messages.swap_adjacent_same')]);
            }
        });
    }
}
<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Services\Calendar;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
class RecipeTest extends TestCase {
    protected string $scope='recipe.laravel.demo';
    protected string $site='recipe';
    public function test_recipe_materials_defaults_shared_notes_and_ratings(): void {
        $this->actingAs($this->account(true));
        $d=['name'=>'鱼','unit'=>'克','notes'=>'先去鱼骨','weekly'=>1,'storable'=>1,'marinate'=>1,'vacuum'=>1,'storage'=>'冷冻','reserve'=>150,'remaining'=>0];
        $this->http('POST','/admin/ingredients',$d)->assertRedirect(); $ingredient=DB::table('ingredients')->where('name','鱼')->value('id');
        $dish=['name'=>'清蒸鱼','kind'=>'荤菜','family'=>'鱼','method'=>'蒸熟','tasty'=>1,'cooked'=>0,'amount'=>[$ingredient=>200]];
        $this->http('POST','/admin/recipes',$dish)->assertRedirect();
        $id=DB::table('recipes')->where('name',$dish['name'])->value('id');
        $this->http('GET','/admin/recipes')->assertOk()->assertSee('食谱内容'); $this->http('GET','/admin/dish/'.$id)->assertOk()->assertSee('先去鱼骨');
        $d['notes']='更新后的共用提示'; $this->http('POST','/admin/ingredients/'.$ingredient,$d)->assertRedirect();
        $this->http('GET','/admin/dish/'.$id)->assertOk()->assertSee($d['notes']);
        $this->http('POST','/admin/dish/'.$id,['tasty'=>0,'cooked'=>1])->assertRedirect();
        $this->assertDatabaseHas('recipes',['id'=>$id,'tasty'=>false,'cooked'=>true]);
        $this->http('DELETE','/admin/ingredients/'.$ingredient)->assertSessionHasErrors('delete');
    }
    public function test_week_rules_restock_swap_skip_and_repeat_protection(): void {
        $this->travelTo(CarbonImmutable::parse('2027-01-03 09:00:00'));
        $this->actingAs($this->account(true)); $this->seed(DemoSeeder::class);
        $this->http('POST','/admin/calendar/settings',['first'=>1,'weekend'=>[6,7]])->assertRedirect();
        $this->artisan('heji:menu')->assertSuccessful();
        $calendar=app(Calendar::class); $start=CarbonImmutable::tomorrow();
        $this->http('POST','/admin/calendar/generate',['day'=>$start->toDateString(),'length'=>7])->assertRedirect()->assertSessionHasNoErrors();
        $this->http('GET','/admin/calendar')->assertOk()->assertSee('需解冻');
        $prev=[];
        for ($i=0;$i<14;$i++) {
            $day=$start->addDays($i)->toDateString();
            $items=$calendar->adjacent($day); $this->assertEmpty(array_intersect($prev,$items)); $prev=$items;
            $dishes=DB::table('meals as m')->join('recipes as r','m.recipe_id','=','r.id')->where('m.day',$day)->pluck('r.family','m.slot');
            $this->assertCount($i%7>=5?4:3,$dishes);
            if ($i%7<3) $this->assertSame(['鱼','虾','sotong'][$i%7],$dishes['荤菜']);
        }
        $this->travelTo($start->setTime(9,0));
        $fish=DB::table('ingredients')->where('name','鱼')->value('id');
        $this->http('POST','/admin/restock',['id'=>$fish,'remaining'=>'40'])->assertRedirect();
        $this->http('GET','/admin/restock')->assertOk()->assertSee('160.000');
        $today=$start->toDateString(); $first=DB::table('meals')->where('day',$today)->where('slot','荤菜')->value('id');
        $tomorrow=DB::table('meals')->where('day',$start->addDay()->toDateString())->where('slot','荤菜')->value('id');
        $this->http('POST','/admin/calendar/swap',['from'=>$first,'to'=>$tomorrow])->assertSessionHasErrors('swap');
        $next=DB::table('meals')->where('day',$start->addWeek()->toDateString())->where('slot','荤菜')->value('id');
        $this->http('POST','/admin/calendar/swap',['from'=>$first,'to'=>$next])->assertRedirect()->assertSessionHasNoErrors();
        $this->http('POST','/admin/calendar/skip',['day'=>$today])->assertRedirect();
        $this->assertDatabaseMissing('meals',['day'=>$today]); $this->assertDatabaseHas('meal_days',['day'=>$today,'skipped'=>true]);
        $max=DB::table('meal_days')->max('day'); $this->http('POST','/admin/calendar/skip',['day'=>$today])->assertRedirect();
        $this->assertSame($max,DB::table('meal_days')->max('day'));
        DB::table('recipes')->where('family','虾')->update(['tasty'=>false]);
        $prior=DB::table('meals')->count();
        $this->http('POST','/admin/calendar/generate',['day'=>$start->addDay()->toDateString(),'length'=>1])->assertSessionHasErrors('menu');
        $this->assertSame($prior,DB::table('meals')->count());
    }

    public function test_skip_moves_every_menu_with_its_meal_ids_across_weekend_and_month(): void {
        $today=CarbonImmutable::parse('2027-01-29'); // Friday. Sunday is the last day of January.
        $this->travelTo($today->setTime(9,0));
        $this->actingAs($this->account(true));
        $this->seed(DemoSeeder::class);
        app(Calendar::class)->arrange($today->subDay(),17);
        $original=DB::table('meals')->orderBy('id')->get();
        $dayIds=DB::table('meal_days')->pluck('id','day');
        $this->http('POST','/admin/calendar/skip',['day'=>'2027-01-29'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('meal_days',['day'=>'2027-01-29','skipped'=>true]);
        $this->assertDatabaseMissing('meals',['day'=>'2027-01-29']);
        foreach ($dayIds as $source=>$id) {
            $moved=$source>='2027-01-29';
            $this->assertDatabaseHas('meal_days',[
                'id'=>$id,'day'=>$moved?CarbonImmutable::parse($source)->addDay()->toDateString():$source,
                'rule_day'=>$moved?$source:null,
            ]);
        }
        $this->http('POST','/admin/calendar/skip',['day'=>'2027-01-29'])->assertRedirect()->assertSessionHasNoErrors();
        $this->http('POST','/admin/calendar/settings',['first'=>1,'weekend'=>[7,6]])->assertRedirect()->assertSessionHasNoErrors();
        $this->travelTo($today->addDay()->setTime(9,0));
        $this->artisan('heji:menu')->assertSuccessful();
        $this->assertDatabaseCount('meals',$original->count());
        foreach ($original as $before) {
            $day=$before->day>='2027-01-29' ? CarbonImmutable::parse($before->day)->addDay()->toDateString() : $before->day;
            $this->assertDatabaseHas('meals',['id'=>$before->id,'day'=>$day,'slot'=>$before->slot,'recipe_id'=>$before->recipe_id]);
        }
        $this->assertDatabaseMissing('meals',['day'=>'2027-01-30','slot'=>'周末其他']); // Friday menu on Saturday.
        $this->assertDatabaseHas('meals',['day'=>'2027-02-01','slot'=>'周末其他']); // Sunday menu on Monday.
        $this->http('GET','/admin/calendar?date=2027-02-01')->assertOk()->assertSee('原定 2027-01-31');
        $this->http('GET','/admin/restock')->assertOk()->assertViewHas('ready',7);

        $this->http('POST','/admin/calendar/skip',['day'=>'2027-01-30'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('meal_days',['day'=>'2027-01-29','skipped'=>true]);
        $this->assertDatabaseHas('meal_days',['day'=>'2027-01-30','skipped'=>true]);
        $this->assertDatabaseCount('meals',$original->count());
        foreach ($original as $before) {
            $day=$before->day>='2027-01-29' ? CarbonImmutable::parse($before->day)->addDays(2)->toDateString() : $before->day;
            $this->assertDatabaseHas('meals',['id'=>$before->id,'day'=>$day,'slot'=>$before->slot,'recipe_id'=>$before->recipe_id]);
        }
        $this->assertDatabaseHas('meal_days',['day'=>'2027-01-31','rule_day'=>'2027-01-29']);
    }

    public function test_skip_preserves_gaps_and_partial_menus_without_generating_dishes(): void {
        $date=CarbonImmutable::parse('2026-12-31');
        $this->travelTo($date->setTime(10,0));
        $this->actingAs($this->account(true));
        $recipe=DB::table('recipes')->insertGetId(['name'=>'剩余的一汤','kind'=>'汤','family'=>'汤','method'=>'煮熟']);
        DB::table('meal_days')->insert([['day'=>'2026-12-31'],['day'=>'2027-01-02'],['day'=>'2027-06-30']]);
        foreach (['2026-12-31','2027-06-30'] as $day) DB::table('meals')->insert(['day'=>$day,'slot'=>'汤','recipe_id'=>$recipe]);
        $this->http('POST','/admin/calendar/skip',['day'=>'2026-12-31'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('meals',2);
        $this->assertDatabaseHas('meals',['day'=>'2027-01-01','slot'=>'汤','recipe_id'=>$recipe]);
        $this->assertDatabaseMissing('meal_days',['day'=>'2027-01-02']);
        $this->assertDatabaseHas('meal_days',['day'=>'2027-01-03','rule_day'=>'2027-01-02']);
        $this->assertDatabaseMissing('meals',['day'=>'2027-01-03']);
        $this->assertDatabaseHas('meals',['day'=>'2027-07-01','slot'=>'汤','recipe_id'=>$recipe]);
        app(Calendar::class)->arrange($date,14,'skip');
        $this->assertDatabaseMissing('meal_days',['day'=>'2027-07-02']);
    }

    public function test_generate_and_postpone_a_continuous_leap_year_through_the_website(): void {
        $this->travelTo(CarbonImmutable::parse('2027-06-01 09:00'));
        $this->actingAs($this->account(true));
        $this->seed(DemoSeeder::class);
        $start=CarbonImmutable::parse('2028-01-01');
        $this->http('GET','/admin/calendar')->assertOk()->assertSee('连续天数');
        $this->http('POST','/admin/calendar/generate',['day'=>'2028-01-01','length'=>366])
            ->assertRedirect('/admin/calendar?date=2028-01-01')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('meal_days',366);
        $before=DB::table('meals')->orderBy('id')->get();
        $days=$before->groupBy('day');
        $recipes=app(Calendar::class)->pantry();
        $previous=[];
        for ($i=0;$i<366;$i++) {
            $date=$start->addDays($i); $weekday=$date->isoWeekday();
            $menu=$days[$date->toDateString()]->keyBy('slot');
            $this->assertCount($weekday>=6?4:3,$menu);
            $this->assertSame($weekday>=6,$menu->has('周末其他'));
            $meat=$recipes[$menu['荤菜']->recipe_id]['family'];
            if ($weekday<=3) $this->assertSame(['鱼','虾','sotong'][$weekday-1],$meat);
            else $this->assertNotContains($meat,['鱼','虾','sotong']);
            $used=[];
            foreach ($menu as $meal) $used=array_merge($used,$recipes[$meal->recipe_id]['items']);
            $this->assertEmpty(array_intersect($previous,$used)); $previous=$used;
        }
        $this->travelTo($start->setTime(9,0));
        foreach ([1,2] as $_) $this->http('POST','/admin/calendar/skip',['day'=>'2028-01-01'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('meal_days',367);
        $this->assertDatabaseHas('meal_days',['day'=>'2029-01-01','rule_day'=>'2028-12-31']);
        $after=DB::table('meals')->orderBy('id')->get();
        $this->assertCount($before->count(),$after);
        foreach ($before as $i=>$row) {
            $this->assertSame([$row->id,$row->recipe_id,$row->slot,CarbonImmutable::parse($row->day)->addDay()->toDateString()],
                [$after[$i]->id,$after[$i]->recipe_id,$after[$i]->slot,$after[$i]->day]);
        }
        $this->http('POST','/admin/calendar/generate',['day'=>'2028-01-01','length'=>0])->assertSessionHasErrors('length');
        $this->http('POST','/admin/calendar/generate',['day'=>'2027-12-31','length'=>1])->assertSessionHasErrors('day');
    }

    public function test_calendar_opens_on_the_local_month_and_navigates_weeks_and_leap_months(): void {
        // It is already March in Kuala Lumpur although UTC is still February.
        $this->travelTo(CarbonImmutable::parse('2028-02-29 17:10:00','UTC'));
        $this->actingAs($this->account(true));
        $this->http('POST','/admin/calendar/settings',['first'=>7,'weekend'=>[5,6]])->assertSessionHasNoErrors();
        $month=$this->http('GET','/admin/calendar')->assertOk()->assertViewHas('mode','month')->assertViewHas('dayCount',31);
        $this->assertSame('2028-03-01',$month->viewData('start')->toDateString());
        $this->assertSame('2028-03-31',$month->viewData('end')->toDateString());
        $this->assertSame(3,$month->viewData('leading'));
        $month->assertSee('id="day-2028-03-01"',false)->assertDontSee('id="day-2028-02-29"',false);
        $feb=$this->http('GET','/admin/calendar?view=month&date=2028-02-29')->assertOk()->assertViewHas('dayCount',29);
        $this->assertSame('2028-02-01',$feb->viewData('start')->toDateString());
        $feb->assertDontSee('>重新排版<',false);
        $jan=$this->http('GET','/admin/calendar?view=month&date=2028-01-31')->assertOk();
        $this->assertSame('2028-02-29',$jan->viewData('next')->toDateString());
        $this->assertSame('2027-12-31',$jan->viewData('previous')->toDateString());
        $week=$this->http('GET','/admin/calendar?view=week&date=2028-03-01')->assertOk()->assertViewHas('dayCount',7);
        $this->assertSame('2028-02-27',$week->viewData('start')->toDateString());
        $this->assertSame('2028-03-04',$week->viewData('end')->toDateString());
        $week->assertSee('id="day-2028-02-29"',false)->assertSee('value="2028-03-02"',false);
        $this->http('GET','/admin/calendar?view=year')->assertSessionHasErrors('view');
    }

    public function test_automatic_generation_and_replanning_begin_tomorrow_and_preserve_today(): void {
        $this->travelTo(CarbonImmutable::parse('2027-12-31 10:00'));
        $this->actingAs($this->account(true));
        $this->seed(DemoSeeder::class);
        $this->artisan('heji:menu')->assertSuccessful();
        $this->assertDatabaseCount('meal_days',14);
        $this->assertSame('2028-01-01',DB::table('meal_days')->min('day'));
        $this->assertSame('2028-01-14',DB::table('meal_days')->max('day'));
        DB::table('meal_days')->insert(['day'=>'2027-12-31']);
        $chicken=DB::table('recipes')->where('family','鸡')->value('id');
        $original=DB::table('meals')->insertGetId(['day'=>'2027-12-31','slot'=>'荤菜','recipe_id'=>$chicken]);
        $this->http('POST','/admin/calendar/generate',['day'=>'2027-12-31','length'=>7])->assertSessionHasErrors('day');
        $this->http('POST','/admin/calendar/settings',['first'=>6,'weekend'=>[5,6]])->assertSessionHasNoErrors();
        $this->artisan('heji:menu')->assertSuccessful();
        $this->http('POST','/admin/calendar/generate',['day'=>'2028-01-01','length'=>7,'view'=>'week'])
            ->assertRedirect('/admin/calendar?date=2028-01-01&view=week')->assertSessionHasNoErrors();
        $this->assertSame(1,DB::table('meals')->where('day','2027-12-31')->count());
        $this->assertDatabaseHas('meals',['id'=>$original,'day'=>'2027-12-31','recipe_id'=>$chicken]);
        $this->http('GET','/admin/calendar')->assertOk()->assertSee('今天没煮')->assertDontSee('>重新排版<',false);
    }

    public function test_adjustment_textbox_validates_saves_and_enforces_edit_permission(): void {
        $editor=$this->account(true);
        $reader=$this->account();
        $role=DB::table('roles')->insertGetId(['name'=>'食谱只读']);
        DB::table('grants')->insert(['role_id'=>$role,'site'=>'recipe','page'=>'recipes']);
        DB::table('memberships')->insert(['user_id'=>$reader->id,'site'=>'recipe','role_id'=>$role]);
        $id=DB::table('recipes')->insertGetId(['name'=>'清汤','kind'=>'汤','family'=>'汤','method'=>'慢煮']);
        $page='/admin/dish/'.$id;
        $this->actingAs($editor);
        $this->http('GET',$page)->assertOk()->assertSee('name="adjustment"',false);
        $this->http('PATCH',$page.'/adjustment',['adjustment'=>str_repeat('a',3001)])->assertSessionHasErrors('adjustment');
        $this->http('PATCH',$page.'/adjustment',['adjustment'=>'<少放盐>'])->assertRedirect($page);
        $this->assertDatabaseHas('recipes',['id'=>$id,'adjustment'=>'<少放盐>','method'=>'慢煮']);
        $this->actingAs($reader);
        $this->http('GET',$page)->assertOk()->assertSee('&lt;少放盐&gt;',false)->assertDontSee('name="adjustment"',false);
        $this->http('PATCH',$page.'/adjustment',['adjustment'=>'新增'])->assertForbidden();
        $this->actingAs($editor);
        $this->http('PATCH',$page.'/adjustment',['adjustment'=>''])->assertRedirect($page);
        $this->assertDatabaseHas('recipes',['id'=>$id,'adjustment'=>null]);
    }

    public function test_solver_revises_an_earlier_dish_to_preserve_tomorrows_postponed_menu(): void {
        $recipes=[];
        foreach (['荤菜','荤菜','素菜','素菜','汤','汤'] as $i=>$kind) {
            $id=$i+1;
            $recipes[$id]=['id'=>$id,'kind'=>$kind,'family'=>['鸡','猪','蛋','豆腐','汤','汤'][$i],'items'=>[$id],'tasty'=>true];
        }
        $days=['2027-01-07','2027-01-08'];
        $preferred=[$days[0]=>['荤菜'=>1,'素菜'=>3,'汤'=>5],$days[1]=>['荤菜'=>1,'素菜'=>4,'汤'=>6]];
        $plan=app(\App\Services\MenuSolver::class)->solve($days,$recipes,1,[],$preferred,[],[],[],[$days[1]=>$days[0]]);
        $this->assertSame(['荤菜'=>2,'素菜'=>3,'汤'=>5],$plan[$days[0]]);
        $this->assertSame($preferred[$days[1]],$plan[$days[1]]);
    }
}

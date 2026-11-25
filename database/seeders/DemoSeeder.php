<?php
namespace Database\Seeders;
use App\Services\Calendar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class DemoSeeder extends Seeder {
    public function run(): void {
        DB::transaction(function() {
            Calendar::lock();
            $families=['荤菜'=>config('heji.meats'),'素菜'=>config('heji.vegetables'),'汤'=>['番茄','玉米','白萝卜','南瓜'],'周末其他'=>config('heji.extras')];
            foreach ($families as $kind=>$list) foreach ($list as $family) {
                $item=DB::table('ingredients')->where('name',$family)->value('id');
                if (!$item) $item=DB::table('ingredients')->insertGetId(['name'=>$family,'unit'=>'克','storage'=>$kind==='荤菜'?'冷冻':'冷藏','vacuum'=>true,'weekly'=>true,'marinate'=>in_array($family,['鸡','鸭','猪']),'notes'=>'按食谱用量备料。']);
                $name=match($kind){'荤菜'=>'家常'.$family,'素菜'=>'清炒'.$family,'汤'=>$family.'汤',default=>'周末'.$family};
                if (DB::table('recipes')->where('name',$name)->exists()) continue;
                $id=DB::table('recipes')->insertGetId(['name'=>$name,'kind'=>$kind,'family'=>$kind==='汤'?'汤':$family,'method'=>match($kind){'汤'=>'食材洗净切块，加水煮至熟软。','素菜'=>'处理食材，切块炒熟后调味。',default=>'按食材特性处理，烹煮至熟后调味。'}]);
                DB::table('recipe_items')->insert(['recipe_id'=>$id,'ingredient_id'=>$item,'quantity'=>200]);
            }
            foreach ([['RICE-01','香米','12.80'],['BEAN-01','红豆','6.50'],['TEA-01','乌龙茶','18.00']] as [$sku,$name,$price]) {
                if (!DB::table('products')->where('sku',$sku)->exists()) DB::table('products')->insert(['sku'=>$sku,'name'=>$name,'price'=>$price,'stock'=>20,'description'=>'示例商品']);
            }
        });
    }
}
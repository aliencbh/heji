<?php
namespace App\Http\Controllers;
use App\Services\Calendar;
use App\Support\Grid;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Validation\{Rule,ValidationException};
class CatalogController {
    public const FIELDS=[
        'products'=>['name'=>'名称','sku'=>'货号','price'=>'售价','active'=>'上架','description'=>'商品说明'],
        'ingredients'=>['name'=>'食材','unit'=>'计量单位','notes'=>'共用注意事项','weekly'=>'每周购买','storable'=>'可以囤货','marinate'=>'需要腌制','vacuum'=>'真空包装','storage'=>'收藏方式','reserve'=>'目标囤货量','remaining'=>'现存量'],
        'recipes'=>['name'=>'食谱名','kind'=>'类别','family'=>'主食材／类型','method'=>'煮法','adjustment'=>'需要调整','tasty'=>'好吃','cooked'=>'已经煮过']
    ];
    public function index(Request $r,string $resource) {
        $cols=['id'=>'编号']+self::FIELDS[$resource];
        if ($resource==='products') $cols['stock']='可用库存';
        $perm=$resource==='products'?'catalog':$resource;
        $opts=['resource'=>$resource,'csvAllowed'=>$r->user()->can('page',$perm.'.export')];
        foreach (['create'=>'/new','edit'=>'/','delete'=>'/'] as $action=>$suffix) {
            if ($r->user()->can('page',$perm.'.'.$action)) $opts[$action]='/admin/'.$resource.$suffix;
        }
        return Grid::show($r,DB::table($resource),$cols,['products'=>'商品与物品','ingredients'=>'食材','recipes'=>'食谱'][$resource],
            $opts);
    }
    public function form(string $resource,?int $id=null) {
        $row=$id?DB::table($resource)->where('id',$id)->firstOrFail():null;
        $items=$resource==='recipes'?DB::table('ingredients')->orderBy('name')->get():collect();
        $quantities=$resource==='recipes' && $id?DB::table('recipe_items')->where('recipe_id',$id)->pluck('quantity','ingredient_id'):collect();
        return view('edit',['resource'=>$resource,'fields'=>self::FIELDS[$resource],'row'=>$row,'items'=>$items,'quantities'=>$quantities]);
    }
    public function save(Request $r,string $resource,?int $id=null) {
        $unique=fn($field)=>Rule::unique($resource,$field)->ignore($id);
        $quantity='required|numeric|decimal:0,3|between:0,999999999.999';
        $rules=match($resource) {
            'products'=>['name'=>'required|string|max:120','sku'=>['required','alpha_dash:ascii','max:48',$unique('sku')],'price'=>'required|numeric|decimal:0,2|between:0,9999999999.99','active'=>'required|boolean','description'=>'nullable|string|max:3000',
                'photo'=>['nullable','file','max:2048','extensions:jpg,jpeg,png','mimes:jpg,jpeg,png','dimensions:max_width=3000,max_height=3000']],
            'ingredients'=>['name'=>['required','string','max:100',$unique('name')],'unit'=>'required|string|max:16','notes'=>'nullable|string|max:3000','storage'=>'required|in:通风处,冷藏,冷冻','reserve'=>$quantity,'remaining'=>$quantity]+array_fill_keys(['weekly','storable','marinate','vacuum'],'required|boolean'),
            'recipes'=>['name'=>['required','string','max:120',$unique('name')],'kind'=>['required',Rule::in(\App\Services\MenuSolver::SLOTS)],'family'=>'required|string|max:32','method'=>'required|string|max:20000','adjustment'=>'nullable|string|max:3000','tasty'=>'required|boolean','cooked'=>'required|boolean','amount'=>'required|array|max:500','amount.*'=>'nullable|numeric|decimal:0,3|gt:0|max:999999999.999'],
        };
        $d=$r->validate($rules); $amount=[];
        if ($resource==='products' && $r->hasFile('photo')) {
            $image=@imagecreatefromstring(file_get_contents($r->file('photo')->getRealPath()));
            if (!$image) throw ValidationException::withMessages(['photo'=>'图片无法读取。']);
            ob_start(); imagepng($image); $bytes=ob_get_clean(); $name=hash('sha256',$bytes);
            if (!Storage::disk('local')->put('products/'.$name.'.png',$bytes)) throw new \RuntimeException('图片保存失败');
            $d['photo']=$name;
        } else unset($d['photo']);
        if ($resource==='recipes') {
            $families=match($d['kind']) {'荤菜'=>config('heji.meats'),'素菜'=>config('heji.vegetables'),'周末其他'=>config('heji.extras'),default=>['汤']};
            if (!in_array($d['family'],$families,true)) throw ValidationException::withMessages(['family'=>'主食材或类型与菜式类别不匹配。']);
            $amount=array_filter($d['amount'],fn($v)=>$v!==null); unset($d['amount']);
            if (!$amount || collect(array_keys($amount))->contains(fn($k)=>!ctype_digit((string)$k)) ||
                DB::table('ingredients')->whereIn('id',array_keys($amount))->count()!==count($amount)) throw ValidationException::withMessages(['amount'=>'请至少选择一种有效食材并填写数量。']);
            if (in_array($d['kind'],['荤菜','素菜'],true) && !DB::table('ingredients')->whereIn('id',array_keys($amount))->where('name',$d['family'])->exists())
                throw ValidationException::withMessages(['amount'=>'准备材料必须包括所选主食材。']);
        }
        DB::transaction(function() use($id,$resource,$d,$amount) {
            if ($resource!=='products') Calendar::lock();
            if ($id) {
                $old=DB::table($resource)->where('id',$id)->lockForUpdate()->firstOrFail();
                if ($resource==='ingredients' && ($old->unit!==$d['unit'] || $old->name!==$d['name']) && DB::table('recipe_items')->where('ingredient_id',$id)->exists())
                    throw ValidationException::withMessages(['unit'=>'食谱已使用该食材，修改名称或单位前请先调整相关食谱。']);
                DB::table($resource)->where('id',$id)->update($d);
            } else $id=DB::table($resource)->insertGetId($d);
            if ($resource==='recipes') {
                $oldItems=DB::table('recipe_items')->where('recipe_id',$id)->orderBy('ingredient_id')->pluck('ingredient_id')->all();
                $newItems=array_map('intval',array_keys($amount)); sort($newItems);
                $invalidates=isset($old) && (!$d['tasty'] || $old->kind!==$d['kind'] || $old->family!==$d['family'] || $oldItems!==$newItems);
                DB::table('recipe_items')->where('recipe_id',$id)->delete();
                foreach ($amount as $ingredient=>$qty) DB::table('recipe_items')->insert(['recipe_id'=>$id,'ingredient_id'=>$ingredient,'quantity'=>$qty]);
                if ($invalidates) DB::table('meals')->where('recipe_id',$id)->where('day','>=',today()->toDateString())->delete();
            }
        });
        return redirect('/admin/'.$resource)->with('ok','已保存。');
    }
    public function delete(string $resource,int $id) {
        try {
            DB::transaction(function() use($resource,$id) {
                if ($resource!=='products') Calendar::lock();
                abort_unless(DB::table($resource)->where('id',$id)->delete(),404);
            });
        } catch(QueryException $e) {
            if ($e->getCode()!=='23503') throw $e;
            throw ValidationException::withMessages(['delete'=>'记录仍被食谱、排期或单据使用，请先处理关联资料。']);
        }
        return redirect('/admin/'.$resource)->with('ok','记录已删除。');
    }
    public function recipe(int $id) {
        return view('recipe',['recipe'=>DB::table('recipes')->where('id',$id)->firstOrFail(),'items'=>DB::table('recipe_items as ri')->join('ingredients as i','i.id','=','ri.ingredient_id')->where('recipe_id',$id)->select('i.*','ri.quantity')->orderBy('name')->get()]);
    }
    public function adjustment(Request $r,int $id) {
        $data=$r->validate(['adjustment'=>'present|nullable|string|max:3000']);
        abort_unless(DB::table('recipes')->where('id',$id)->update($data),404);
        return redirect('/admin/dish/'.$id)->with('ok','已保存调整记录。');
    }
    public function flags(Request $r,int $id) {
        $d=$r->validate(['tasty'=>'required_without:cooked|boolean','cooked'=>'required_without:tasty|boolean']);
        DB::transaction(function() use($id,$d) {
            Calendar::lock(); DB::table('recipes')->where('id',$id)->lockForUpdate()->firstOrFail();
            DB::table('recipes')->where('id',$id)->update($d);
            if (isset($d['tasty']) && !$d['tasty']) DB::table('meals')->where('recipe_id',$id)->where('day','>=',today()->toDateString())->delete();
        }); return back();
    }
    public function photo(string $hash) {
        $path=Storage::disk('local')->path('products/'.$hash.'.png');
        abort_unless(is_file($path),404); return response()->file($path,['Content-Type'=>'image/png','X-Content-Type-Options'=>'nosniff']);
    }
}
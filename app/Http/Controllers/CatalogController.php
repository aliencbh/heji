<?php
namespace App\Http\Controllers;
use App\Services\Calendar;
use App\Support\Grid;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Validation\{Rule,ValidationException};
class CatalogController {
    public public static function fields() { return=[
        'products'=>['name'=>__('messages.grid_name'),'sku'=>__('messages.grid_sku'),'price'=>__('messages.grid_price'),'active'=>__('messages.grid_active'),'description'=>__('messages.grid_description')],
        'ingredients'=>['name'=>__('messages.grid_ingredient_name'),'unit'=>__('messages.grid_unit'),'notes'=>__('messages.grid_notes'),'weekly'=>__('messages.grid_weekly'),'storable'=>__('messages.grid_storable'),'marinate'=>__('messages.grid_marinate'),'vacuum'=>__('messages.grid_vacuum'),'storage'=>__('messages.grid_storage'),'reserve'=>__('messages.grid_reserve'),'remaining'=>__('messages.grid_remaining')],
        'recipes'=>['name'=>__('messages.grid_recipe_name'),'kind'=>__('messages.grid_kind'),'family'=>__('messages.grid_family'),'method'=>__('messages.grid_method'),'adjustment'=>__('messages.grid_adjustment'),'tasty'=>__('messages.grid_tasty'),'cooked'=>__('messages.grid_cooked')]
    ]; }
    public function index(Request $r,string $resource) {
        $cols=['id'=>__('messages.grid_id')]+self::fields()[$resource];
        if ($resource==='products') $cols['stock']=__('messages.grid_stock');
        $perm=$resource==='products'?'catalog':$resource;
        $opts=['resource'=>$resource,'csvAllowed'=>$r->user()->can('page',$perm.'.export')];
        foreach (['create'=>'/new','edit'=>'/','delete'=>'/'] as $action=>$suffix) {
            if ($r->user()->can('page',$perm.'.'.$action)) $opts[$action]='/admin/'.$resource.$suffix;
        }
        return Grid::show($r,DB::table($resource),$cols,['products'=>__('messages.grid_products_title'),'ingredients'=>__('messages.grid_ingredients_title'),'recipes'=>__('messages.grid_recipes_title')][$resource],
            $opts);
    }
    public function form(string $resource,?int $id=null) {
        $row=$id?DB::table($resource)->where('id',$id)->firstOrFail():null;
        $items=$resource==='recipes'?DB::table('ingredients')->orderBy('name')->get():collect();
        $quantities=$resource==='recipes' && $id?DB::table('recipe_items')->where('recipe_id',$id)->pluck('quantity','ingredient_id'):collect();
        return view('edit',['resource'=>$resource,'fields'=>self::fields()[$resource],'row'=>$row,'items'=>$items,'quantities'=>$quantities]);
    }
    public function save(Request $r,string $resource,?int $id=null) {
        $unique=fn($field)=>Rule::unique($resource,$field)->ignore($id);
        $quantity='required|numeric|decimal:0,3|between:0,999999999.999';
        $rules=match($resource) {
            'products'=>['name'=>'required|string|max:120','sku'=>['required','alpha_dash:ascii','max:48',$unique('sku')],'price'=>'required|numeric|decimal:0,2|between:0,9999999999.99','active'=>'required|boolean','description'=>'nullable|string|max:3000',
                'photo'=>['nullable','file','max:2048','extensions:jpg,jpeg,png','mimes:jpg,jpeg,png','dimensions:max_width=3000,max_height=3000']],
            'ingredients'=>['name'=>['required','string','max:100',$unique('name')],'unit'=>'required|string|max:16','notes'=>'nullable|string|max:3000','storage'=>'required|in:' . \App\Support\DbValues::STORAGE_LIST,'reserve'=>$quantity,'remaining'=>$quantity]+array_fill_keys(['weekly','storable','marinate','vacuum'],'required|boolean'),
            'recipes'=>['name'=>['required','string','max:120',$unique('name')],'kind'=>['required',Rule::in(\App\Services\MenuSolver::SLOTS)],'family'=>'required|string|max:32','method'=>'required|string|max:20000','adjustment'=>'nullable|string|max:3000','tasty'=>'required|boolean','cooked'=>'required|boolean','amount'=>'required|array|max:500','amount.*'=>'nullable|numeric|decimal:0,3|gt:0|max:999999999.999'],
        };
        $d=$r->validate($rules); $amount=[];
        if ($resource==='products' && $r->hasFile('photo')) {
            $image=@imagecreatefromstring(file_get_contents($r->file('photo')->getRealPath()));
            if (!$image) throw ValidationException::withMessages(['photo'=>__('messages.image_unreadable')]);
            ob_start(); imagepng($image); $bytes=ob_get_clean(); $name=hash('sha256',$bytes);
            if (!Storage::disk('local')->put('products/'.$name.'.png',$bytes)) throw new \RuntimeException(__('messages.image_save_failed'));
            $d['photo']=$name;
        } else unset($d['photo']);
        if ($resource==='recipes') {
            $families=match($d['kind']) {\App\Support\DbValues::MEAT=>config('heji.meats'),\App\Support\DbValues::VEG=>config('heji.vegetables'),\App\Support\DbValues::WEEKEND_OTHER=>config('heji.extras'),default=>[\App\Support\DbValues::SOUP]};
            if (!in_array($d['family'],$families,true)) throw ValidationException::withMessages(['family'=>__('messages.family_mismatch')]);
            $amount=array_filter($d['amount'],fn($v)=>$v!==null); unset($d['amount']);
            if (!$amount || collect(array_keys($amount))->contains(fn($k)=>!ctype_digit((string)$k)) ||
                DB::table('ingredients')->whereIn('id',array_keys($amount))->count()!==count($amount)) throw ValidationException::withMessages(['amount'=>__('messages.amount_select')]);
            if (in_array($d['kind'],[\App\Support\DbValues::MEAT,\App\Support\DbValues::VEG],true) && !DB::table('ingredients')->whereIn('id',array_keys($amount))->where('name',$d['family'])->exists())
                throw ValidationException::withMessages(['amount'=>__('messages.amount_include_family')]);
        }
        DB::transaction(function() use($id,$resource,$d,$amount) {
            if ($resource!=='products') Calendar::lock();
            if ($id) {
                $old=DB::table($resource)->where('id',$id)->lockForUpdate()->firstOrFail();
                if ($resource==='ingredients' && ($old->unit!==$d['unit'] || $old->name!==$d['name']) && DB::table('recipe_items')->where('ingredient_id',$id)->exists())
                    throw ValidationException::withMessages(['unit'=>__('messages.unit_used')]);
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
        return redirect('/admin/'.$resource)->with('ok',__('messages.saved_msg'));
    }
    public function delete(string $resource,int $id) {
        try {
            DB::transaction(function() use($resource,$id) {
                if ($resource!=='products') Calendar::lock();
                abort_unless(DB::table($resource)->where('id',$id)->delete(),404);
            });
        } catch(QueryException $e) {
            if ($e->getCode()!=='23503') throw $e;
            throw ValidationException::withMessages(['delete'=>__('messages.record_in_use')]);
        }
        return redirect('/admin/'.$resource)->with('ok',__('messages.record_deleted'));
    }
    public function recipe(int $id) {
        return view('recipe',['recipe'=>DB::table('recipes')->where('id',$id)->firstOrFail(),'items'=>DB::table('recipe_items as ri')->join('ingredients as i','i.id','=','ri.ingredient_id')->where('recipe_id',$id)->select('i.*','ri.quantity')->orderBy('name')->get()]);
    }
    public function adjustment(Request $r,int $id) {
        $data=$r->validate(['adjustment'=>'present|nullable|string|max:3000']);
        abort_unless(DB::table('recipes')->where('id',$id)->update($data),404);
        return redirect('/admin/dish/'.$id)->with('ok',__('messages.save_adjust_record_msg'));
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
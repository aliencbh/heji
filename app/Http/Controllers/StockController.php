<?php
namespace App\Http\Controllers;
use App\Services\Inventory;
use App\Support\Grid;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class StockController {
    public function front(Request $r) {
        return view('stock',['products'=>DB::table('products')->where('active',true)->orderBy('name')->get(),'mine'=>DB::table('purchases')->where('user_id',$r->user()->id)->orderByDesc('id')->paginate(10)]);
    }
    public function create(Request $r) {
        $d=$r->validate(['client_ref'=>'required|uuid','product_id'=>'required|integer|exists:products,id','quantity'=>'required|integer|between:1,1000000','note'=>'nullable|string|max:500']);
        abort_unless(DB::table('products')->where('id',$d['product_id'])->value('active'),422);
        DB::transaction(function() use ($r,$d) {
            $actor=$r->user()->id;
            DB::table('users')->where('id',$actor)->lockForUpdate()->firstOrFail();
            if (!DB::table('purchases')->where('user_id',$actor)->where('client_ref',$d['client_ref'])->exists())
                DB::table('purchases')->insert($d+['user_id'=>$actor]);
        });
        return back()->with('ok','采购单已提交。');
    }
    public function purchases(Request $r) {
        return Grid::show($r,DB::table('purchases'),['id'=>'采购单','product_id'=>'物品编号','quantity'=>'数量','note'=>'备注','status'=>'状态','created_at'=>'提交时间'],'采购收货',['actions'=>$r->user()->can('page','purchase.manage.update')?'purchases':null,'csvAllowed'=>$r->user()->can('page','purchase.manage.export')]);
    }
    public function change(Request $r,int $id,Inventory $inventory) {
        $d=$r->validate(['status'=>'required|in:已收货,已取消']); $inventory->purchaseStatus($id,$d['status'],$r->user()->id); return back();
    }
    public function adjust(Request $r,Inventory $inventory) {
        $d=$r->validate(['product_id'=>'required|integer|exists:products,id','delta'=>'required|integer|not_in:0|between:-1000000,1000000','reason'=>'required|string|max:160']);
        DB::transaction(fn()=>$inventory->move($d['product_id'],$d['delta'],$d['reason'],$r->user()->id)); return back()->with('ok','已记录库存流水。');
    }
    public function history(Request $r) {
        return Grid::show($r,DB::table('movements'),['id'=>'流水号','product_id'=>'物品','delta'=>'变动量','reason'=>'原因','user_id'=>'经手人','created_at'=>'发生时间'],'库存流水',['csvAllowed'=>$r->user()->can('page','stock.history.export')]);
    }
}
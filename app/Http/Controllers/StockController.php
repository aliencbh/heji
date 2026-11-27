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
        return back()->with('ok',__('messages.purchase_submitted'));
    }
    public function purchases(Request $r) {
        return Grid::show($r,DB::table('purchases'),['id'=>__('messages.grid_purchase_id'),'product_id'=>__('messages.grid_product_id'),'quantity'=>__('messages.grid_quantity'),'note'=>__('messages.grid_note'),'status'=>__('messages.grid_status'),'created_at'=>__('messages.grid_purchase_time')],__('messages.grid_purchase_title'),['actions'=>$r->user()->can('page','purchase.manage.update')?'purchases':null,'csvAllowed'=>$r->user()->can('page','purchase.manage.export')]);
    }
    public function change(Request $r,int $id,Inventory $inventory) {
        $d=$r->validate(['status'=>'required|in:' . \App\Support\DbValues::RECEIVED . ',' . \App\Support\DbValues::CANCELLED]); $inventory->purchaseStatus($id,$d['status'],$r->user()->id); return back();
    }
    public function adjust(Request $r,Inventory $inventory) {
        $d=$r->validate(['product_id'=>'required|integer|exists:products,id','delta'=>'required|integer|not_in:0|between:-1000000,1000000','reason'=>'required|string|max:160']);
        DB::transaction(fn()=>$inventory->move($d['product_id'],$d['delta'],$d['reason'],$r->user()->id)); return back()->with('ok',__('messages.stock_move_recorded'));
    }
    public function history(Request $r) {
        return Grid::show($r,DB::table('movements'),['id'=>__('messages.grid_movement_id'),'product_id'=>__('messages.grid_product'),'delta'=>__('messages.grid_delta'),'reason'=>__('messages.grid_reason'),'user_id'=>__('messages.grid_actor'),'created_at'=>__('messages.grid_move_time')],__('messages.grid_movements_title'),['csvAllowed'=>$r->user()->can('page','stock.history.export')]);
    }
}
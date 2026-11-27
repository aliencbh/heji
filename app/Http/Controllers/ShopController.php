<?php
namespace App\Http\Controllers;
use App\Services\Inventory;
use App\Support\Grid;
use Illuminate\Http\Request;
use Illuminate\Support\{Str,Facades\DB};
class ShopController {
    public function home(Request $r) {
        $d=$r->validate(['search'=>'nullable|string|max:100']);
        $items=DB::table('products')->where('active',true)->when(!empty($d['search']),fn($q)=>$q->where('name','ilike','%'.$d['search'].'%'))->orderBy('name')->paginate(24)->withQueryString();
        $cart=$r->session()->get('cart',[]);
        $nonce=$r->session()->get('checkout',Str::uuid()->toString()); $r->session()->put('checkout',$nonce);
        $chosen=DB::table('products')->whereIn('id',array_keys($cart))->orderBy('name')->get();
        return view('shop',compact('items','cart','chosen','nonce'));
    }
    public function cart(Request $r) {
        $d=$r->validate(['product'=>'required|integer|exists:products,id','quantity'=>'required|integer|min:0|max:999']);
        $cart=$r->session()->get('cart',[]);
        if ($d['quantity']) $cart[$d['product']]=(int)$d['quantity']; else unset($cart[$d['product']]);
        abort_if(count($cart)>80,422,__('messages.cart_max_80'));
        $r->session()->put(['cart'=>$cart,'checkout'=>Str::uuid()->toString()]); return redirect('/#basket');
    }
    public function checkout(Request $r,Inventory $inventory) {
        $d=$r->validate(['nonce'=>'required|uuid']);
        if (!hash_equals((string)$r->session()->get('checkout'),$d['nonce'])) {
            $old=DB::table('orders')->where('user_id',$r->user()->id)->where('nonce',$d['nonce'])->firstOrFail();
            return redirect('/orders/'.$old->id);
        }
        $id=$inventory->checkout($r->user()->id,$r->session()->get('cart',[]),$d['nonce']);
        $r->session()->forget(['cart','checkout']); return redirect('/orders/'.$id);
    }
    public function detail(Request $r,int $id) {
        $order=DB::table('orders')->where('id',$id)->firstOrFail();
        abort_unless($order->user_id===$r->user()->id || $r->user()->can('page','orders'),403);
        return view('order',['order'=>$order,'items'=>DB::table('order_lines')->where('order_id',$id)->orderBy('id')->get()]);
    }
    public function orders(Request $r) {
        $admin=$r->is('admin/*'); $q=DB::table('orders'); if (!$admin) $q->where('user_id',$r->user()->id);
        return Grid::show($r,$q,['id'=>__('messages.grid_order_id'),'status'=>__('messages.grid_status'),'total'=>__('messages.grid_total'),'created_at'=>__('messages.grid_order_time')],__('messages.grid_orders_title'),['link'=>'/orders/','actions'=>$admin && $r->user()->can('page','orders.update')?'orders':null,'csvAllowed'=>!$admin || $r->user()->can('page','orders.export')]);
    }
    public function change(Request $r,int $id,Inventory $inventory) {
        $d=$r->validate(['status'=>'required|in:' . \App\Support\DbValues::COMPLETED . ',' . \App\Support\DbValues::CANCELLED]); $inventory->orderStatus($id,$d['status'],$r->user()->id); return back();
    }
}
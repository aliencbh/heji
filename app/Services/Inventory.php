<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class Inventory {
    // Caller owns the transaction. Order all product locks by ascending ID for multi-item operations.
    public function move(int $id,int $delta,string $reason,int $actor): object {
        $row=DB::table('products')->where('id',$id)->lockForUpdate()->firstOrFail();
        $after=$row->stock+$delta;
        if ($after<0 || $after>2147483647) throw ValidationException::withMessages(['quantity'=>$row->name.' 的库存不足或超出上限。']);
        DB::table('products')->where('id',$id)->update(['stock'=>$after]);
        DB::table('movements')->insert(['product_id'=>$id,'delta'=>$delta,'reason'=>$reason,'user_id'=>$actor]);
        return $row;
    }
    public function checkout(int $actor,array $cart,string $nonce): int {
        return DB::transaction(function() use ($actor,$cart,$nonce) {
            DB::table('users')->where('id',$actor)->lockForUpdate()->firstOrFail();
            $existing=DB::table('orders')->where('user_id',$actor)->where('nonce',$nonce)->value('id');
            if ($existing) return $existing;
            if (!$cart) throw ValidationException::withMessages(['cart'=>'请先选择商品。']);
            ksort($cart,SORT_NUMERIC);
            $id=DB::table('orders')->insertGetId(['user_id'=>$actor,'nonce'=>$nonce,'total'=>0]);
            foreach ($cart as $pid=>$qty) {
                $p=$this->move($pid,-$qty,'订单 #'.$id,$actor);
                if (!$p->active) throw ValidationException::withMessages(['cart'=>$p->name.' 已下架。']);
                DB::table('order_lines')->insert(['order_id'=>$id,'product_id'=>$pid,'price'=>$p->price,'name'=>$p->name,'quantity'=>$qty]);
            }
            DB::update('UPDATE orders SET total=(SELECT sum(price*quantity) FROM order_lines WHERE order_id=?) WHERE id=?',[$id,$id]);
            return $id;
        },3);
    }
    public function orderStatus(int $id,string $status,int $actor): void {
        DB::transaction(function() use($id,$status,$actor) {
            $row=DB::table('orders')->where('id',$id)->lockForUpdate()->firstOrFail();
            if ($row->status===$status) return;
            abort_unless($row->status==='待处理',409,'订单已结案。');
            if ($status==='已取消') foreach (DB::table('order_lines')->where('order_id',$id)->orderBy('product_id')->get() as $line) {
                $this->move($line->product_id,$line->quantity,'撤销订单 #'.$id,$actor);
            }
            DB::table('orders')->where('id',$id)->update(['status'=>$status]);
        },3);
    }
    public function purchaseStatus(int $id,string $status,int $actor): void {
        DB::transaction(function() use($id,$status,$actor) {
            $p=DB::table('purchases')->where('id',$id)->lockForUpdate()->firstOrFail();
            if ($p->status===$status) return;
            abort_unless($p->status==='待收货',409,'采购单已结案。');
            if ($status==='已收货') $this->move($p->product_id,$p->quantity,'采购收货 #'.$id,$actor);
            DB::table('purchases')->where('id',$id)->update(['status'=>$status]);
        },3);
    }
}
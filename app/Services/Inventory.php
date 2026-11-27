<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class Inventory {
    // Caller owns the transaction. Order all product locks by ascending ID for multi-item operations.
    public function move(int $id,int $delta,string $reason,int $actor): object {
        $row=DB::table('products')->where('id',$id)->lockForUpdate()->firstOrFail();
        $after=$row->stock+$delta;
        if ($after<0 || $after>2147483647) throw ValidationException::withMessages(['quantity'=>$row->name..__('messages.stock_out_of_range')]);
        DB::table('products')->where('id',$id)->update(['stock'=>$after]);
        DB::table('movements')->insert(['product_id'=>$id,'delta'=>$delta,'reason'=>$reason,'user_id'=>$actor]);
        return $row;
    }
    public function checkout(int $actor,array $cart,string $nonce): int {
        return DB::transaction(function() use ($actor,$cart,$nonce) {
            DB::table('users')->where('id',$actor)->lockForUpdate()->firstOrFail();
            $existing=DB::table('orders')->where('user_id',$actor)->where('nonce',$nonce)->value('id');
            if ($existing) return $existing;
            if (!$cart) throw ValidationException::withMessages(['cart'=>__('messages.select_product')]);
            ksort($cart,SORT_NUMERIC);
            $id=DB::table('orders')->insertGetId(['user_id'=>$actor,'nonce'=>$nonce,'total'=>0]);
            foreach ($cart as $pid=>$qty) {
        $p=$this->move($pid,-$qty, __('messages.order_no', ['id' => $id]), $actor);
                if (!$p->active) throw ValidationException::withMessages(['cart'=>$p->name..__('messages.off_shelf')]);
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
            abort_unless($row->status===\App\Support\DbValues::PENDING,409,__('messages.order_closed'));
            if ($status===\App\Support\DbValues::CANCELLED) foreach (DB::table('order_lines')->where('order_id',$id)->orderBy('product_id')->get() as $line) {
        $p=$this->move($pid,-$qty, __('messages.order_no', ['id' => $id]), $actor);
            }
            DB::table('orders')->where('id',$id)->update(['status'=>$status]);
        },3);
    }
    public function purchaseStatus(int $id,string $status,int $actor): void {
        DB::transaction(function() use($id,$status,$actor) {
            $p=DB::table('purchases')->where('id',$id)->lockForUpdate()->firstOrFail();
            if ($p->status===$status) return;
            abort_unless($p->status===\App\Support\DbValues::PENDING_RECEIPT,409,__('messages.purchase_closed'));
        if ($status===\App\Support\DbValues::RECEIVED) $this->move($p->product_id, $p->quantity, __('messages.purchase_receipt_no', ['id' => $id]), $actor);
            DB::table('purchases')->where('id',$id)->update(['status'=>$status]);
        },3);
    }
}
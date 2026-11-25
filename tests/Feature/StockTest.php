<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
class StockTest extends TestCase {
    protected string $scope='stock.laravel.demo';
    protected string $site='stock';
    public function test_purchase_receive_once_adjust_and_history(): void {
        $u=$this->account(true); $this->actingAs($u); $p=$this->product();
        $this->http('GET','/')->assertOk(); $this->http('POST','/purchase',['product_id'=>$p,'quantity'=>-1])->assertSessionHasErrors('quantity');
        $form=['product_id'=>$p,'quantity'=>5,'client_ref'=>(string)\Illuminate\Support\Str::uuid(),'note'=>'常规补货'];
        foreach ([1,2] as $_) $this->http('POST','/purchase',$form)->assertRedirect();
        $this->assertDatabaseCount('purchases',1);
        $id=DB::table('purchases')->where('product_id',$p)->value('id');
        $this->http('GET','/admin/purchases')->assertOk();
        foreach ([1,2] as $_) $this->http('POST','/admin/purchases/'.$id,['status'=>'已收货'])->assertRedirect();
        $this->assertDatabaseHas('products',['id'=>$p,'stock'=>15]);
        $this->http('GET','/admin/adjust')->assertOk(); $this->http('POST','/admin/adjust',['product_id'=>$p,'delta'=>-2,'reason'=>'盘点'])->assertRedirect();
        $this->http('GET','/admin/history')->assertOk()->assertSee('盘点');
        $this->assertDatabaseHas('products',['id'=>$p,'stock'=>13]);
        $this->assertDatabaseHas('audit.products',['actor_id'=>$u->id,'action'=>'UPDATE']);
    }
}
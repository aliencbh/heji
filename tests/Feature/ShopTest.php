<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
class ShopTest extends TestCase {
    protected string $scope='shop.laravel.demo';
    public function test_cart_checkout_retry_ownership_and_cancel(): void {
        $buyer=$this->account(); $dev=$this->account(true); $p=$this->product();
        $this->actingAs($buyer); $this->http('GET','/')->assertOk()->assertSee('购物车');
        $this->http('POST','/cart',['product'=>$p,'quantity'=>3])->assertRedirect();
        $nonce=session('checkout'); $this->http('POST','/checkout',['nonce'=>$nonce])->assertRedirect();
        $this->http('POST','/checkout',['nonce'=>$nonce])->assertRedirect();
        $this->assertDatabaseHas('products',['id'=>$p,'stock'=>7]); $this->assertDatabaseHas('orders',['total'=>12.75,'user_id'=>$buyer->id]);
        $id=DB::table('orders')->where('nonce',$nonce)->value('id');
        $this->http('GET','/orders/'.$id)->assertOk(); $this->http('GET','/orders')->assertOk();
        $this->actingAs($this->account()); $this->http('GET','/orders/'.$id)->assertForbidden();
        $this->actingAs($dev); $this->http('GET','/admin/orders')->assertOk();
        foreach ([1,2] as $_) $this->http('POST','/admin/orders/'.$id,['status'=>'已取消'])->assertRedirect();
        $this->assertDatabaseHas('products',['id'=>$p,'stock'=>10]);
    }
    public function test_insufficient_stock_rolls_back_all_lines(): void {
        $first=$this->product(); $last=$this->product(); $this->actingAs($this->account());
        foreach ([$first=>2,$last=>99] as $product=>$quantity) $this->http('POST','/cart',compact('product','quantity'))->assertRedirect();
        $this->http('POST','/checkout',['nonce'=>session('checkout')])->assertSessionHasErrors('quantity');
        $this->assertDatabaseHas('products',['id'=>$first,'stock'=>10]); $this->assertDatabaseCount('orders',0);
    }
    public function test_product_crud_photo_and_filtered_csv(): void {
        $this->actingAs($this->account(true)); $p='/admin/products';
        $this->http('GET',$p.'/new')->assertOk();
        $d=['sku'=>'PHOTO-1','name'=>'=自定义','price'=>'1.50','active'=>'1'];
        $this->http('POST',$p,$d+['photo'=>UploadedFile::fake()->create('bad.txt',1,'text/plain')])->assertSessionHasErrors('photo');
        $this->http('POST',$p,$d+['photo'=>UploadedFile::fake()->image('ok.png',6,6)])->assertRedirect();
        $row=DB::table('products')->where('sku',$d['sku'])->first(); $this->http('GET','/media/'.$row->photo)->assertOk();
        $this->http('GET',$p.'/'.$row->id.'/edit')->assertOk();
        $d['description']='<tag>'; $this->http('POST',$p.'/'.$row->id,$d)->assertRedirect();
        $this->http('GET',$p.'?q[sku]=PHOTO-1')->assertOk()->assertSee('&lt;tag&gt;',false);
        $response=$this->http('GET',$p.'?csv=1&columns[]=name&only='.$row->id)->assertOk();
        $this->assertStringContainsString("'=自定义",$response->streamedContent());
        $this->http('DELETE',$p.'/'.$row->id)->assertRedirect(); $this->assertDatabaseMissing('products',['id'=>$row->id]);
    }
}
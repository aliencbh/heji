<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Support\Facades\{DB,Hash};
class CommonTest extends TestCase {
    public function test_required_runtime_versions_and_redis(): void {
        $this->assertStringStartsWith('13.',app()->version());
        $pg=(int)DB::selectOne('show server_version_num')->server_version_num;
        $this->assertGreaterThanOrEqual(180000,$pg);
        $this->assertNotFalse(\Illuminate\Support\Facades\Redis::connection()->ping());
    }
    public function test_registration_login_logout_and_site_boundaries(): void {
        foreach (['shop','stock','recipe'] as $site) {
            $this->site=$site; $this->http('GET','/admin')->assertRedirect('/login');
            $this->http('GET','/login')->assertOk(); $this->http('GET','/register')->assertOk();
        }
        $this->http('POST','/register',['name'=>'A','email'=>'a@example.test','password'=>'short'])->assertSessionHasErrors('password');
        $d=['email'=>'a@example.test','password'=>'New-Account-2026'];
        $this->http('POST','/register',$d+['name'=>'A','password_confirmation'=>$d['password'],'developer'=>true])->assertRedirect('/admin');
        $u=DB::table('users')->where('email',$d['email'])->first(); $this->assertFalse($u->developer); $this->assertTrue(Hash::check($d['password'],$u->password));
        $this->http('GET','/admin')->assertOk(); $this->http('GET','/admin/recipes')->assertForbidden();
        $this->http('POST','/logout')->assertRedirect('/login');
        $this->http('POST','/login',['email'=>$d['email'],'password'=>'bad'])->assertSessionHasErrors('email');
        $this->http('POST','/login',$d)->assertRedirect();
        $this->http('GET','/admin/dev/status')->assertForbidden();
        $this->http('GET','/checkout')->assertNotFound();
    }
    public function test_roles_matrix_site_scope_and_sensitive_routes(): void {
        $dev=$this->account(true); $worker=$this->account(); $this->actingAs($dev);
        $this->http('GET','/admin/roles')->assertOk();
        $this->http('POST','/admin/roles',['name'=>'值班'])->assertRedirect(); $role=DB::table('roles')->where('name','值班')->value('id');
        $this->http('POST','/admin/roles/permissions',['role'=>$role,'pages'=>['developer']])->assertSessionHasErrors();
        $this->http('POST','/admin/roles/permissions',['role'=>$role,'pages'=>['catalog']])->assertRedirect();
        $this->http('POST','/admin/roles/assign',['email'=>$worker->email,'role'=>$role])->assertRedirect();
        $this->actingAs($worker); $this->http('GET','/admin/products')->assertOk(); $this->http('DELETE','/admin/dev/audit')->assertForbidden();
        $this->http('DELETE','/admin/products/1')->assertForbidden(); $this->http('GET','/admin/products?csv=1')->assertForbidden();
        $this->site='stock'; $this->http('GET','/admin/products')->assertForbidden();
        $this->site='shop'; $this->actingAs($dev); $this->http('DELETE','/admin/roles/'.$role)->assertUnprocessable();
        $this->http('POST','/admin/roles/assign',['email'=>$worker->email])->assertRedirect();
        $this->http('DELETE','/admin/roles/'.$role)->assertRedirect(); $this->assertDatabaseMissing('roles',['id'=>$role]);
    }
    public function test_developer_logs_audit_backup_and_metrics(): void {
        $this->actingAs($this->account(true));
        $this->artisan('heji:logs')->assertSuccessful();
        foreach (['errors','tests'] as $type) {
            $path='/admin/dev/logs/'.$type; $d=['scope'=>'manual','message'=>'原始条目'];
            $this->http('POST',$path,$d)->assertRedirect(); $id=DB::table('log_entries')->where('message','原始条目')->value('id');
            $this->http('GET',$path.'?scope=manual')->assertOk()->assertSee('原始条目');
            $this->http('POST',$path.'/'.$id,['scope'=>'manual','message'=>'已修改'])->assertRedirect();
            $this->http('DELETE',$path.'/'.$id)->assertRedirect();
        }
        $this->http('GET','/admin/dev/audit?table=users')->assertOk();
        $log=DB::table('audit.users')->orderByDesc('id')->first(); $this->assertStringNotContainsString('password',$log->new_data);
        $this->http('DELETE','/admin/dev/audit',['table'=>'users','ids'=>[$log->id]])->assertRedirect();
        $this->assertDatabaseMissing('audit.users',['id'=>$log->id]);
        $this->http('GET','/admin/dev/backups')->assertOk(); $this->http('POST','/admin/dev/backups/interval',['minutes'=>20])->assertRedirect();
        $this->http('POST','/admin/dev/backups')->assertRedirect(); $b=DB::table('backups')->first(); $this->assertGreaterThan(0,$b->size);
        $this->http('GET','/admin/dev/backups/'.$b->id)->assertDownload($b->name.'.dump');
        $this->http('POST','/admin/dev/backups/'.$b->id,['label'=>'验收'])->assertRedirect();
        $this->artisan('heji:backup',['--due'=>true])->assertSuccessful();
        $this->http('DELETE','/admin/dev/backups/'.$b->id)->assertRedirect();
        $this->assertDatabaseMissing('backups',['id'=>$b->id]);
        $this->http('GET','/admin/dev/status')->assertOk()->assertSee('内存');
        $this->assertSame(1048576,strlen($this->http('GET','/admin/dev/speed')->assertOk()->getContent()));
        $this->artisan('heji:developer',['email'=>$this->account()->email])->assertSuccessful();
    }
}
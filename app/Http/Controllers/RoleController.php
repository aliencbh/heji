<?php
namespace App\Http\Controllers;
use App\Support\Events;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
use Illuminate\Validation\Rule;
class RoleController {
    public function index() {
        return view('roles',['roles'=>DB::table('roles')->orderBy('id')->get(),'grants'=>DB::table('grants')->where('site',request()->attributes->get('site'))->get()->groupBy('role_id'),'groups'=>config('heji.groups.'.request()->attributes->get('site'))]);
    }
    public function save(Request $r) {
        $site=$r->attributes->get('site');
        $pages=array_merge(...array_values(config('heji.groups.'.$site)));
        $d=$r->validate(['role'=>'required|integer|exists:roles,id','pages'=>'sometimes|array','pages.*'=>[Rule::in(array_keys($pages))]]);
        DB::transaction(function() use($d,$site) {
            $role=DB::table('roles')->where('id',$d['role'])->lockForUpdate()->firstOrFail();
            abort_if($role->name==='开发员',403);
            DB::table('grants')->where('role_id',$role->id)->where('site',$site)->delete();
            foreach (array_unique($d['pages']??[]) as $page) DB::table('grants')->insert(['role_id'=>$role->id,'site'=>$site,'page'=>$page]);
            Events::record('roles.permissions',$site.' / '.$role->name);
        });
        return back()->with('ok','权限已保存。');
    }
    public function create(Request $r) {
        $d=$r->validate(['name'=>'required|string|max:64|unique:roles']);
        DB::table('roles')->insert($d); return back();
    }
    public function delete(int $id) {
        DB::transaction(function() use($id) {
            $role=DB::table('roles')->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_if($role->name==='开发员' || DB::table('memberships')->where('role_id',$id)->exists(),422,'请先转移此角色的成员。');
            DB::table('roles')->delete($id); Events::record('roles.deleted',$role->name);
        }); return back();
    }
    public function assign(Request $r) {
        $d=$r->validate(['email'=>'required|email|exists:users,email','role'=>'nullable|integer|exists:roles,id']);
        $site=$r->attributes->get('site');
        DB::transaction(function() use($d,$site) {
            $user=DB::table('users')->where('email',$d['email'])->lockForUpdate()->firstOrFail();
            abort_if($user->developer,403);
            $key=['user_id'=>$user->id,'site'=>$site];
            if (!empty($d['role'])) {
                abort_if(DB::table('roles')->where('id',$d['role'])->value('name')==='开发员',403);
                DB::table('memberships')->updateOrInsert($key,['role_id'=>$d['role']]);
            } else DB::table('memberships')->where($key)->delete();
            Events::record('roles.assigned',$site.' / '.$user->id);
        }); return back()->with('ok','站点角色已更新。');
    }
}
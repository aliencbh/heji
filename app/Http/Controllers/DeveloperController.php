<?php
namespace App\Http\Controllers;
use App\Services\Backups;
use App\Support\{Events,Grid};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class DeveloperController {
    public function audit(Request $r) {
        $tables=DB::table('pg_catalog.pg_tables')->where('schemaname','audit')->orderBy('tablename')->pluck('tablename')->all();
        $d=$r->validate(['table'=>['nullable',\Illuminate\Validation\Rule::in($tables)]]);
        $table=$d['table']??'users';
        return Grid::show($r,DB::table('audit.'.$table),['id'=>'日志号','at'=>'时间','actor_id'=>'用户','action'=>'操作','record_id'=>'记录','old_data'=>'变更前','new_data'=>'变更后'],'数据库日志',['tables'=>$tables,'table'=>$table,'actions'=>'audit']);
    }
    public function auditDelete(Request $r) {
        $d=$r->validate(['table'=>'required|string|max:64','ids'=>'required|array|min:1|max:1000','ids.*'=>'required|integer|min:1|distinct']);
        abort_unless(DB::table('pg_catalog.pg_tables')->where('schemaname','audit')->where('tablename',$d['table'])->exists(),404);
        DB::transaction(function() use($d) {
            DB::table('audit.'.$d['table'])->whereIn('id',$d['ids'])->delete();
            Events::record('audit.deleted',$d['table'].' / '.implode(',',$d['ids']));
        }); return back()->with('ok','所选日志已删除。');
    }
    public function logs(Request $r,string $type) {
        $category=$type==='tests'?'测试':'错误'; $q=DB::table('log_entries')->where('category',$category);
        $scope=$r->validate(['scope'=>'nullable|string|max:64'])['scope']??null;
        $scopes=(clone $q)->distinct()->orderBy('scope')->pluck('scope');
        if ($scope) $q->where('scope',$scope);
        return Grid::show($r,$q,['id'=>'编号','scope'=>'来源','message'=>'内容','created_at'=>'时间'],$category.'日志',['actions'=>'logs','scopes'=>$scopes,'type'=>$type]);
    }
    public function logSave(Request $r,string $type,?int $id=null) {
        $d=$r->validate(['scope'=>'required|string|max:64','message'=>'required|string|max:30000']);
        $category=$type==='tests'?'测试':'错误';
        if ($id) abort_unless(DB::table('log_entries')->where('category',$category)->where('id',$id)->update($d),404);
        else DB::table('log_entries')->insert($d+['category'=>$category]);
        Events::record('logs.saved',$category); return back();
    }
    public function logDelete(string $type,int $id) {
        abort_unless(DB::table('log_entries')->where('category',$type==='tests'?'测试':'错误')->where('id',$id)->delete(),404);
        Events::record('logs.deleted',(string)$id); return back();
    }
    public function backups(Request $r) {
        $d=$r->validate(['from'=>'nullable|date_format:Y-m-d','until'=>'nullable|date_format:Y-m-d']);
        if (!empty($d['from']) && !empty($d['until']) && $d['from']>$d['until']) throw \Illuminate\Validation\ValidationException::withMessages(['until'=>'结束日期早于开始日期。']);
        $q=DB::table('backups');
        if (!empty($d['from'])) $q->where('created_at','>=',\Carbon\CarbonImmutable::parse($d['from'])->utc());
        if (!empty($d['until'])) $q->where('created_at','<',\Carbon\CarbonImmutable::parse($d['until'])->addDay()->utc());
        return Grid::show($r,$q,['id'=>'编号','name'=>'备份名称','label'=>'备注','size'=>'字节','created_at'=>'时间'],'数据库备份',['actions'=>'backups','minutes'=>DB::table('settings')->where('key','backup_minutes')->value('value')]);
    }
    public function backup(Backups $backups) { $backups->create(); return back()->with('ok','完整数据库备份已生成。'); }
    public function interval(Request $r) {
        $d=$r->validate(['minutes'=>'required|integer|between:5,525600']);
        DB::table('settings')->where('key','backup_minutes')->update(['value'=>(string)$d['minutes']]); return back();
    }
    public function backupEdit(Request $r,int $id) {
        $d=$r->validate(['label'=>'nullable|string|max:120']); abort_unless(DB::table('backups')->where('id',$id)->update($d),404); return back();
    }
    public function backupDelete(Backups $backups,int $id) { $backups->remove($id); return back(); }
    public function download(Backups $backups,int $id) {
        $row=DB::table('backups')->where('id',$id)->firstOrFail(); Events::record('backup.download',$row->name);
        $path=$backups->directory().'/'.$row->name.'.dump'; abort_unless(is_file($path),404);
        return response()->download($path,null,['Cache-Control'=>'private, no-store']);
    }
    public function status() {
        $read=fn($f)=>is_readable($f)?trim(file_get_contents($f)):'未提供';
        $data=['应用节点'=>gethostname(),'内核'=>php_uname('s').' '.php_uname('r'),'PHP'=>PHP_VERSION,'Laravel'=>app()->version(),
          '数据库'=>DB::selectOne('show server_version')->server_version,'系统负载（1 / 5 / 15 分钟）'=>implode(' / ',sys_getloadavg()?:[]),
          '容器内存用量（字节）'=>$read('/sys/fs/cgroup/memory.current'),'容器内存上限（字节）'=>$read('/sys/fs/cgroup/memory.max'),
          'CPU 使用统计（微秒）'=>$read('/sys/fs/cgroup/cpu.stat'),'数据盘可用 / 总量（GiB）'=>round(disk_free_space(storage_path())/1073741824,2).' / '.round(disk_total_space(storage_path())/1073741824,2)];
        return view('status',compact('data'));
    }
    public function speed() {
        return response(random_bytes(1048576),200,['Content-Type'=>'application/octet-stream','Cache-Control'=>'no-store','Content-Length'=>'1048576']);
    }
}
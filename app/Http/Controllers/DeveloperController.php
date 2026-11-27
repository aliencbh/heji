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
        return Grid::show($r,DB::table('audit.'.$table),['id'=>__('messages.grid_log_id'),'at'=>__('messages.grid_time'),'actor_id'=>__('messages.grid_user'),'action'=>__('messages.grid_action'),'record_id'=>__('messages.grid_record'),'old_data'=>__('messages.grid_old_data'),'new_data'=>__('messages.grid_new_data')],__('messages.grid_audit_log'),['tables'=>$tables,'table'=>$table,'actions'=>'audit']);
    }
    public function auditDelete(Request $r) {
        $d=$r->validate(['table'=>'required|string|max:64','ids'=>'required|array|min:1|max:1000','ids.*'=>'required|integer|min:1|distinct']);
        abort_unless(DB::table('pg_catalog.pg_tables')->where('schemaname','audit')->where('tablename',$d['table'])->exists(),404);
        DB::transaction(function() use($d) {
            DB::table('audit.'.$d['table'])->whereIn('id',$d['ids'])->delete();
            Events::record('audit.deleted',$d['table'].' / '.implode(',',$d['ids']));
        }); return back()->with('ok',__('messages.logs_deleted'));
    }
    public function logs(Request $r,string $type) {
        $category=$type==='tests'?\App\Support\DbValues::TEST:\App\Support\DbValues::ERROR; $q=DB::table('log_entries')->where('category',$category);
        $scope=$r->validate(['scope'=>'nullable|string|max:64'])['scope']??null;
        $scopes=(clone $q)->distinct()->orderBy('scope')->pluck('scope');
        if ($scope) $q->where('scope',$scope);
        return Grid::show($r,$q,['id'=>__('messages.grid_id'),'scope'=>__('messages.grid_scope'),'message'=>__('messages.grid_message'),'created_at'=>__('messages.grid_time')],(==='tests'?__('messages.grid_test_logs'):__('messages.grid_error_logs')),['actions'=>'logs','scopes'=>$scopes,'type'=>$type]);
    }
    public function logSave(Request $r,string $type,?int $id=null) {
        $d=$r->validate(['scope'=>'required|string|max:64','message'=>'required|string|max:30000']);
        $category=$type==='tests'?\App\Support\DbValues::TEST:\App\Support\DbValues::ERROR;
        if ($id) abort_unless(DB::table('log_entries')->where('category',$category)->where('id',$id)->update($d),404);
        else DB::table('log_entries')->insert($d+['category'=>$category]);
        Events::record('logs.saved',$category); return back();
    }
    public function logDelete(string $type,int $id) {
        abort_unless(DB::table('log_entries')->where('category',$type==='tests'?\App\Support\DbValues::TEST:\App\Support\DbValues::ERROR)->where('id',$id)->delete(),404);
        Events::record('logs.deleted',(string)$id); return back();
    }
    public function backups(Request $r) {
        $d=$r->validate(['from'=>'nullable|date_format:Y-m-d','until'=>'nullable|date_format:Y-m-d']);
        if (!empty($d['from']) && !empty($d['until']) && $d['from']>$d['until']) throw \Illuminate\Validation\ValidationException::withMessages(['until'=>__('messages.end_before_start')]);
        $q=DB::table('backups');
        if (!empty($d['from'])) $q->where('created_at','>=',\Carbon\CarbonImmutable::parse($d['from'])->utc());
        if (!empty($d['until'])) $q->where('created_at','<',\Carbon\CarbonImmutable::parse($d['until'])->addDay()->utc());
        return Grid::show($r,$q,['id'=>__('messages.grid_id'),'name'=>__('messages.grid_backup_name'),'label'=>__('messages.grid_label'),'size'=>__('messages.grid_size'),'created_at'=>__('messages.grid_time')],__('messages.grid_backup_title'),['actions'=>'backups','minutes'=>DB::table('settings')->where('key','backup_minutes')->value('value')]);
    }
    public function backup(Backups $backups) { $backups->create(); return back()->with('ok',__('messages.backup_created')); }
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
        $read=fn($f)=>is_readable($f)?trim(file_get_contents($f)):__('messages.not_provided');
        $data=[__('messages.app_node')=>gethostname(),__('messages.kernel')=>php_uname('s').' '.php_uname('r'),'PHP'=>PHP_VERSION,'Laravel'=>app()->version(),
          __('messages.database_label')=>DB::selectOne('show server_version')->server_version,__('messages.load_label')=>implode(' / ',sys_getloadavg()?:[]),
          __('messages.mem_used_label')=>$read('/sys/fs/cgroup/memory.current'),__('messages.mem_max_label')=>$read('/sys/fs/cgroup/memory.max'),
          __('messages.cpu_label')=>$read('/sys/fs/cgroup/cpu.stat'),__('messages.disk_label')=>round(disk_free_space(storage_path())/1073741824,2).' / '.round(disk_total_space(storage_path())/1073741824,2)];
        return view('status',compact('data'));
    }
    public function speed() {
        return response(random_bytes(1048576),200,['Content-Type'=>'application/octet-stream','Cache-Control'=>'no-store','Content-Length'=>'1048576']);
    }
}
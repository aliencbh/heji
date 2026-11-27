<?php
namespace App\Services;
use App\Support\Events;
use Illuminate\Support\Facades\{Cache,DB};
use Symfony\Component\Process\Process;
class Backups {
    public function directory(): string {
        $dir=storage_path('app/private/backups'); if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new \RuntimeException(__('messages.backup_dir_failed'));
        return $dir;
    }
    public function create(bool $due=false): ?string {
        return Cache::lock('heji-full-backup',1900)->block(2,function() use($due) {
            if ($due) {
                $minutes=(int)DB::table('settings')->where('key','backup_minutes')->value('value');
                $last=DB::table('backups')->max('created_at');
                if ($last && now()->parse($last)->addMinutes($minutes)->isFuture()) return null;
            }
            $name=now()->format('ymdHis'); $path=$this->directory().'/'.$name.'.dump';
            if (file_exists($path)) abort(409,__('messages.backup_exists'));
            $tmp=$path.'.'.bin2hex(random_bytes(5)).'.part';
            $c=config('database.connections.pgsql');
            $pg=['PGHOST'=>$c['host'],'PGPORT'=>(string)$c['port'],'PGUSER'=>$c['username'],'PGPASSWORD'=>$c['password'],'PGDATABASE'=>$c['database']];
            try {
                (new Process(['pg_dump','--format=custom','--no-password','--file='.$tmp],null,$pg,null,1800))->mustRun();
                (new Process(['pg_restore','--list',$tmp],null,$pg,null,60))->mustRun();
                chmod($tmp,0600);
                DB::transaction(function() use($tmp,$path,$name) {
                    DB::table('backups')->insert(['name'=>$name,'size'=>filesize($tmp)]);
                    Events::record('backup.created',$name);
                    if (!rename($tmp,$path)) throw new \RuntimeException(__('messages.backup_write_failed'));
                });
            } catch (\Throwable $e) {
                if (is_file($path) && !DB::table('backups')->where('name',$name)->exists()) unlink($path);
                throw $e;
            } finally { if (is_file($tmp)) unlink($tmp); }
            return $name;
        });
    }
    public function remove(int $id): void {
        $trash=null; $path=null;
        try {
            DB::transaction(function() use($id,&$trash,&$path) {
                $b=DB::table('backups')->where('id',$id)->lockForUpdate()->firstOrFail();
                $path=$this->directory().'/'.$b->name.'.dump'; $trash=$path.'.delete';
                if (is_file($path) && !rename($path,$trash)) throw new \RuntimeException(__('messages.backup_in_use'));
                DB::table('backups')->delete($id); Events::record('backup.deleted',$b->name);
            });
        } catch(\Throwable $e) { if ($trash && is_file($trash)) rename($trash,$path); throw $e; }
        if ($trash && is_file($trash)) unlink($trash);
    }
}
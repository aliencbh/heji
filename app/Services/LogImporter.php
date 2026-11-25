<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;
class LogImporter {
    public function run(): void {
        foreach (['laravel','nginx','postgres','redis','php','scheduler','runtime','tests'] as $category) {
            foreach (glob(storage_path('logs/'.$category.'/*.log'))?:[] as $path) {
                if (is_link($path) || !is_readable($path)) continue;
                DB::transaction(function() use($category,$path) {
                    if ($category==='tests') {
                        DB::select('select pg_advisory_xact_lock(924378)');
                        if (!is_file($path)) return;
                    }
                    DB::select('select pg_advisory_xact_lock(hashtext(?))',[$path]);
                    $key=$category.'/'.basename($path); $old=DB::table('log_offsets')->where('path',$key)->first();
                    $f=fopen($path,'rb'); if (!$f) return;
                    try {
                        $stat=fstat($f);
                        $start=$old && $old->inode===$stat['ino'] && $old->position<=$stat['size'] ? $old->position : 0;
                        fseek($f,$start);
                        for ($i=0;$i<1000 && ($line=fgets($f,32769))!==false;$i++) {
                            if (!str_ends_with($line,"\n") && strlen($line)<32768) break;
                            $message=mb_scrub(trim($line),'UTF-8');
                            $message=preg_replace('/((?:password|passwd|authorization|token|cookie)[\s"=:]+)[^\s,]+/i','$1[已隐藏]',$message);
                            DB::table('log_entries')->insert(['category'=>$category==='tests'?'测试':'错误','scope'=>$category==='tests'?substr(basename($path,'.log'),0,64):$category,'message'=>str_replace("\0",'',$message)]);
                            $start=ftell($f);
                        }
                        if (!$old || $old->inode!==$stat['ino'] || $old->position!==$start) DB::table('log_offsets')->updateOrInsert(['path'=>$key],['inode'=>$stat['ino'],'position'=>$start]);
                    } finally { fclose($f); }
                });
            }
        }
    }
}
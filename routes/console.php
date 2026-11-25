<?php
use App\Models\User;
use App\Services\{Backups,Calendar,LogImporter};
use App\Support\Events;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Artisan,DB,Schedule};
Artisan::command('heji:developer {email}',function() {
    $u=User::where('email',mb_strtolower($this->argument('email')))->first();
    if (!$u) { $this->error('请先通过网页注册该账号。'); return 1; }
    DB::transaction(function() use($u) { $u->developer=true; $u->save(); Events::record('developer.granted',(string)$u->id); });
    $this->info('已设置开发员身份。'); return 0;
});
Artisan::command('heji:menu',function(Calendar $calendar) {
    if (DB::table('recipes')->where('tasty',true)->exists()) $calendar->arrange(CarbonImmutable::tomorrow(config('app.timezone')),14);
});
Artisan::command('heji:backup {--due}',function(Backups $backups) { $this->info($backups->create($this->option('due'))??'尚未到备份时间'); });
Artisan::command('heji:reset-test-logs',function() {
    DB::transaction(function() {
        DB::select('select pg_advisory_xact_lock(924378)');
        foreach (glob(storage_path('logs/tests/*.log'))?:[] as $file) {
            if (!unlink($file)) throw new RuntimeException('无法清空本轮测试日志。');
        }
        DB::table('log_offsets')->where('path','like','tests/%')->delete();
        DB::table('log_entries')->where('category','测试')->delete();
        Events::record('testlogs.reset');
    });
});
Artisan::command('heji:logs',function(LogImporter $importer) { $importer->run(); });
Schedule::command('heji:menu')->dailyAt('00:05')->withoutOverlapping(45)->appendOutputTo(storage_path('logs/scheduler/menu.log'));
Schedule::command('heji:backup --due')->everyMinute()->withoutOverlapping(35)->runInBackground()->appendOutputTo(storage_path('logs/scheduler/backup.log'));
Schedule::command('heji:logs')->everyMinute()->withoutOverlapping(10)->appendOutputTo(storage_path('logs/scheduler/import.log'));
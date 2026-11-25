<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        DB::unprepared(file_get_contents(database_path('schema.sql')));
    }
    public function down(): void {
        throw new LogicException('生产结构仅支持向前迁移，恢复请使用独立数据库。');
    }
};
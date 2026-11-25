<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        DB::statement('ALTER TABLE meal_days ADD COLUMN rule_day date');
        DB::statement('ALTER TABLE meals
            DROP CONSTRAINT meals_day_fkey,
            ADD CONSTRAINT meals_day_fkey FOREIGN KEY (day) REFERENCES meal_days(day)
            ON UPDATE CASCADE ON DELETE CASCADE');
    }

    public function down(): void {
        throw new LogicException('生产结构仅支持向前迁移，恢复请使用独立数据库。');
    }
};

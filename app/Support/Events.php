<?php
namespace App\Support;
use Illuminate\Support\Facades\DB;
class Events {
    public static function record(string $event,string $detail=''): void {
        DB::table('security_events')->insert(['actor_id'=>auth()->id(),'event'=>$event,'details'=>$detail]);
    }
}
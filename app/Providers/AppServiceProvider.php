<?php
namespace App\Providers;
use App\Models\User;
use Illuminate\Support\Facades\{DB,Gate,RateLimiter};
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
class AppServiceProvider extends ServiceProvider {
    public function boot(): void {
        Gate::define('developer',fn(User $user)=>$user->developer);
        Gate::define('page',function(User $user,string $page) {
            if ($user->developer) return true;
            return DB::table('memberships as m')->join('grants as g','g.role_id','=','m.role_id')
                ->where('m.user_id',$user->id)->where('m.site',request()->attributes->get('site'))
                ->whereColumn('g.site','m.site')->where('g.page',$page)->exists();
        });
        RateLimiter::for('access',fn(Request $r)=>[
            Limit::perMinute(25)->by('ip:'.$r->ip()),
            Limit::perMinute(6)->by('account:'.hash('sha256',strtolower(is_string($r->input('email'))?$r->input('email'):'').'|'.$r->ip()))
        ]);
    }
}
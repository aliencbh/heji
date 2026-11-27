<?php
use App\Http\Controllers\{AccessController,CalendarController,CatalogController,DeveloperController,RoleController,ShopController,StockController};
use App\Http\Middleware\CatalogAccess;
use Illuminate\Support\Facades\Route;

Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, ['zh-Hans', 'en'], true)) {
        session(['locale' => $locale]);
    }
    return redirect()->back(fallback: '/');
})->name('locale.switch');

foreach (array_keys(config('heji.sites')) as $site) {
 Route::domain($site.'.laravel.demo')->group(function() use($site) {
	Route::get('/health',fn()=>response('ok'));
    Route::middleware('guest')->group(function() {
        Route::get('/login',[AccessController::class,'form']);
        Route::get('/register',[AccessController::class,'form']);
        Route::post('/login',[AccessController::class,'login'])->middleware('throttle:access');
        Route::post('/register',[AccessController::class,'register'])->middleware('throttle:access');
    });
    if ($site==='shop') {
        Route::get('/',[ShopController::class,'home']);
        Route::post('/cart',[ShopController::class,'cart'])->middleware('throttle:120,1')->block();
        Route::get('/media/{hash}',[CatalogController::class,'photo'])->where('hash','[a-f0-9]{64}');
    }
    Route::middleware('auth')->group(function() use($site) {
        Route::post('/logout',[AccessController::class,'logout']);
        Route::view('/admin','dashboard');
        if ($site==='recipe') {
            Route::redirect('/','/admin/calendar');
            Route::middleware('page:calendar')->prefix('admin/calendar')->controller(CalendarController::class)->group(function() {
                Route::get('/','index'); Route::post('generate','generate')->middleware('page:calendar.edit'); Route::post('skip','skip')->middleware('page:calendar.edit'); Route::post('swap','swap')->middleware('page:calendar.edit'); Route::post('settings','settings')->middleware('page:calendar.settings');
            });
            Route::get('/admin/restock',[CalendarController::class,'restock'])->middleware('page:restock');
            Route::post('/admin/restock',[CalendarController::class,'remaining'])->middleware(['page:restock','page:restock.update']);
            Route::get('/admin/dish/{id}',[CatalogController::class,'recipe'])->middleware('page:recipes')->whereNumber('id');
            Route::patch('/admin/dish/{id}/adjustment',[CatalogController::class,'adjustment'])->middleware(['page:recipes','page:recipes.edit'])->whereNumber('id');
            Route::post('/admin/dish/{id}',[CatalogController::class,'flags'])->middleware(['page:recipes','page:recipes.edit'])->whereNumber('id');
        }
        if ($site==='shop') {
            Route::post('/checkout',[ShopController::class,'checkout'])->block();
            Route::get('/orders',[ShopController::class,'orders']);
            Route::get('/orders/{id}',[ShopController::class,'detail'])->whereNumber('id');
            Route::get('/admin/orders',[ShopController::class,'orders'])->middleware('page:orders');
            Route::post('/admin/orders/{id}',[ShopController::class,'change'])->middleware(['page:orders','page:orders.update'])->whereNumber('id');
        }
        if ($site==='stock') {
            Route::get('/',[StockController::class,'front'])->middleware('page:purchase.create');
            Route::post('/purchase',[StockController::class,'create'])->middleware('page:purchase.create');
            Route::get('/admin/purchases',[StockController::class,'purchases'])->middleware('page:purchase.manage');
            Route::post('/admin/purchases/{id}',[StockController::class,'change'])->middleware(['page:purchase.manage','page:purchase.manage.update'])->whereNumber('id');
            Route::get('/admin/history',[StockController::class,'history'])->middleware('page:stock.history');
            Route::view('/admin/adjust','adjust')->middleware('page:stock.adjust');
            Route::post('/admin/adjust',[StockController::class,'adjust'])->middleware('page:stock.adjust');
        }
        Route::prefix('admin/roles')->middleware('can:developer')->controller(RoleController::class)->group(function() {
            Route::get('/','index'); Route::post('/','create'); Route::post('permissions','save'); Route::post('assign','assign'); Route::delete('{id}','delete')->whereNumber('id');
        });
        Route::prefix('admin/dev')->middleware('can:developer')->controller(DeveloperController::class)->group(function() {
            Route::get('audit','audit'); Route::delete('audit','auditDelete');
            Route::get('logs/{type}','logs')->whereIn('type',['tests','errors']);
            Route::post('logs/{type}/{id?}','logSave')->whereIn('type',['tests','errors'])->whereNumber('id');
            Route::delete('logs/{type}/{id}','logDelete')->whereIn('type',['tests','errors'])->whereNumber('id');
            Route::get('backups','backups'); Route::post('backups','backup')->middleware('throttle:4,1');
            Route::post('backups/interval','interval'); Route::post('backups/{id}','backupEdit')->whereNumber('id');
            Route::delete('backups/{id}','backupDelete')->whereNumber('id'); Route::get('backups/{id}','download')->whereNumber('id');
            Route::get('status','status'); Route::get('speed','speed')->middleware('throttle:10,1');
        });
        Route::prefix('admin/{resource}')->where(['resource'=>$site==='recipe'?'ingredients|recipes':'products'])->middleware(CatalogAccess::class)->controller(CatalogController::class)->group(function() {
            Route::get('/','index'); Route::get('new','form'); Route::get('{id}/edit','form')->whereNumber('id');
            Route::post('{id?}','save')->whereNumber('id'); Route::delete('{id}','delete')->whereNumber('id');
        });
    });
 });
}
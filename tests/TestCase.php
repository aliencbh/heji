<?php
namespace Tests;
use App\Models\User;
use Illuminate\Foundation\Testing\{DatabaseTransactions,TestCase as LaravelTest};
use Illuminate\Support\Facades\{DB,Hash};
abstract class TestCase extends LaravelTest {
    use DatabaseTransactions;
    protected string $scope='common';
    protected string $site='shop';
    private array $cookieJar=[];
    protected function setUp(): void {
        if (getenv('HEJI_TEST_PROJECT')!=='isolated') throw new \RuntimeException('测试只能使用隔离的 Compose 数据库。');
        xdebug_start_code_coverage();
        parent::setUp();
        $this->step('开始，独立数据库事务');
    }
    protected function tearDown(): void {
        $coverage=xdebug_get_code_coverage(); xdebug_stop_code_coverage();
        $lines=[];
        foreach ($coverage as $file=>$hits) {
            if (str_starts_with($file,app_path().'/')) $lines[substr($file,strlen(base_path())+1)]=array_keys(array_filter($hits,fn($v)=>$v>0));
        }
        file_put_contents(base_path('reports/coverage.jsonl'),json_encode(['case'=>$this->name(),'scope'=>$this->scope,'lines'=>$lines])."\n",FILE_APPEND|LOCK_EX);
        parent::tearDown();
    }
    protected function step(string $message): void {
        file_put_contents(storage_path('logs/tests/'.$this->scope.'.log'),now()->toIso8601String().' '.$this->name().' '.$message."\n",FILE_APPEND|LOCK_EX);
    }
    protected function account(bool $developer=false): User {
        $u=User::create(['name'=>'测试人员','email'=>bin2hex(random_bytes(5)).'@example.test','password'=>Hash::make('Test-Password-2026')]);
        if ($developer) { $u->developer=true; $u->save(); }
        return $u;
    }
    protected function http(string $method,string $path,array $data=[]) {
        $url='https://'.$this->site.'.laravel.demo'.$path;
        $files=[];
        foreach ($data as $k=>$v) if ($v instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) { $files[$k]=$v; unset($data[$k]); }
        $result=$this->call($method,$url,$data,$this->cookieJar[$this->site]??[],$files);
        foreach ($result->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) unset($this->cookieJar[$this->site][$cookie->getName()]);
            else $this->cookieJar[$this->site][$cookie->getName()]=$cookie->getValue();
        }
        $this->step($method.' '.strtok($path,'?').' → '.$result->status());
        return $result;
    }
    protected function product(): int {
        return DB::table('products')->insertGetId(['sku'=>bin2hex(random_bytes(5)),'name'=>'测试商品','price'=>'4.25','stock'=>10]);
    }
}
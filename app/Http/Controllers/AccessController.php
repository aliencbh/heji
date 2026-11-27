<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Support\Events;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB,Hash};
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
class AccessController {
    public function form(Request $r) { return view('access',['register'=>$r->is('register')]); }
    public function register(Request $r) {
        if (is_string($r->input('email'))) $r->merge(['email'=>mb_strtolower($r->input('email'))]);
        $d=$r->validate(['name'=>'required|string|max:100','email'=>'required|email|max:254|unique:users','password'=>['required','confirmed','max:72',Password::min(8)->letters()->numbers()]]);
        if (strlen($d['password'])>72) throw ValidationException::withMessages(['password'=>'密码的 UTF-8 编码最多 72 字节。']);
        $u=DB::transaction(fn()=>User::create(['name'=>$d['name'],'email'=>$d['email'],'password'=>Hash::make($d['password'])]));
        Auth::login($u); $r->session()->regenerate();
        Events::record('account.registered');
        return redirect('/admin');
    }
    public function login(Request $r) {
        $d=$r->validate(['email'=>'required|email|max:254','password'=>'required|string|max:256']);
        $d['email']=mb_strtolower($d['email']);
        if (!Auth::attempt($d)) {
            Events::record('login.failed');
            throw ValidationException::withMessages(['email'=>'邮箱或密码不正确。']);
        }
        $r->session()->regenerate(); Events::record('login.success');
        return redirect()->intended('/admin');
    }
    public function logout(Request $r) {
        Events::record('logout'); Auth::logout(); $r->session()->invalidate(); $r->session()->regenerateToken();
        return redirect('/login');
    }
}
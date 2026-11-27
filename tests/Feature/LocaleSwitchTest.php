<?php
namespace Tests\Feature;
use Tests\TestCase;
class LocaleSwitchTest extends TestCase
{
    public function test_switch_to_english_stores_session(): void
    {
        $response = $this->get('/locale/en');
        $response->assertRedirect();
        $this->assertSame('en', session('locale'));
    }
    public function test_switch_to_chinese_stores_session(): void
    {
        $this->withSession(['locale' => 'en'])->get('/locale/zh-Hans')->assertRedirect();
        $this->assertSame('zh-Hans', session('locale'));
    }
    public function test_invalid_locale_is_ignored(): void
    {
        $this->withSession(['locale' => 'zh-Hans'])->get('/locale/fr')->assertRedirect();
        $this->assertSame('zh-Hans', session('locale'));
    }
    public function test_translate_button_exists_in_layout(): void
    {
        $response = $this->get('/login');
        $response->assertOk();
        $response->assertSee('translate-box', false);
        $response->assertSee('locale/zh-Hans', false);
        $response->assertSee('locale/en', false);
        $response->assertSee('<svg', false);
    }
    public function test_translated_text_changes_with_locale(): void
    {
        $this->withSession(['locale' => 'en'])->get('/login')->assertSee('Login', false);
        $this->withSession(['locale' => 'zh-Hans'])->get('/login')->assertSee('登录', false);
    }
}

<?php
namespace Tests\Feature;
use Tests\TestCase;
class PasswordEyeTest extends TestCase
{
    public function test_login_page_has_password_eye_button(): void
    {
        $response = $this->get('/login');
        $response->assertOk();
        $response->assertSee('password-eye', false);
        $response->assertSee('data-testid="password-eye"', false);
        $response->assertSee('type="password"', false);
    }
    public function test_password_input_is_wrapped_for_eye_button(): void
    {
        $response = $this->get('/login');
        $response->assertSee('password-wrap', false);
    }
}

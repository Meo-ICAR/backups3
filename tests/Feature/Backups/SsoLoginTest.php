<?php

namespace Tests\Feature\Backups;

use App\Models\SocialiteUser;
use App\Models\User;
use Tests\TestCase;

class SsoLoginTest extends TestCase
{
    public function test_the_home_page_opens_the_admin_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_the_login_page_shows_the_brand_banner(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('brand/logos/unico-backup.jpg', false)
            ->assertSee('brand/icons/unico-backup-64.png', false);
    }

    public function test_the_login_page_offers_microsoft_and_google(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('Microsoft')->assertSee('Google');
    }

    public function test_the_buttons_redirect_to_the_providers_with_the_panel_callback(): void
    {
        config([
            'services.microsoft.client_id' => 'ms-id', 'services.microsoft.client_secret' => 'ms-secret',
            'services.microsoft.redirect' => 'https://backups3.test/admin/oauth/callback/microsoft',
            'services.google.client_id' => 'g-id', 'services.google.client_secret' => 'g-secret',
            'services.google.redirect' => 'https://backups3.test/admin/oauth/callback/google',
        ]);

        $microsoft = $this->get('/admin/oauth/microsoft')->assertRedirect()->headers->get('Location');
        $google = $this->get('/admin/oauth/google')->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://login.microsoftonline.com/', $microsoft);
        $this->assertStringContainsString(urlencode('https://backups3.test/admin/oauth/callback/microsoft'), $microsoft);
        $this->assertStringStartsWith('https://accounts.google.com/', $google);
    }

    public function test_the_socialite_account_model_uses_the_shared_table(): void
    {
        $this->assertSame('socialite_users', (new SocialiteUser)->getTable());
        $this->assertSame(User::class, (new SocialiteUser)->user()->getRelated()::class);
    }
}

<?php

namespace Tests\Feature\Backups;

use App\Models\User;
use Filament\Facades\Filament;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    public function test_only_the_listed_emails_can_access_the_panel(): void
    {
        config(['backup_paths.admin_emails' => ['Admin@Example.com']]);
        $panel = Filament::getPanel('admin');

        $this->assertTrue((new User(['email' => 'admin@example.com']))->canAccessPanel($panel));
        $this->assertFalse((new User(['email' => 'altro@example.com']))->canAccessPanel($panel));
    }

    public function test_nobody_can_access_when_no_email_is_configured(): void
    {
        config(['backup_paths.admin_emails' => []]);

        $this->assertFalse((new User(['email' => 'admin@example.com']))->canAccessPanel(Filament::getPanel('admin')));
    }
}

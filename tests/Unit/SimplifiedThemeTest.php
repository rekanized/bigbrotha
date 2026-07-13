<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SimplifiedThemeTest extends TestCase
{
    public function test_app_exposes_only_the_simplified_theme_entry_point(): void
    {
        $publicPath = dirname(__DIR__, 2).'/public/css';
        $appStyles = trim((string) file_get_contents($publicPath.'/app.css'));
        $themeStyles = (string) file_get_contents($publicPath.'/pages/simplified-theme.css');

        $this->assertSame("@import url('./pages/simplified-theme.css');", $appStyles);
        $this->assertStringNotContainsString("data-theme", $themeStyles);
        $this->assertStringNotContainsString("theme-toggle", $themeStyles);
    }

    public function test_application_layout_has_no_theme_switching_runtime(): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/app.blade.php');

        $this->assertStringNotContainsString('bigbrotha-theme', $layout);
        $this->assertStringNotContainsString('data-theme-toggle', $layout);
        $this->assertStringNotContainsString('prefers-color-scheme', $layout);
    }

    public function test_operator_layout_exposes_compact_mobile_navigation_and_a_skip_link(): void
    {
        $projectPath = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($projectPath.'/resources/views/layouts/app.blade.php');
        $navigation = (string) file_get_contents($projectPath.'/resources/views/layouts/partials/sidebar-navigation.blade.php');

        $this->assertStringContainsString('class="skip-link"', $layout);
        $this->assertStringContainsString('class="mobile-navigation page-card"', $layout);
        $this->assertStringContainsString('id="main-content"', $layout);
        $this->assertStringContainsString("'navigationIdSuffix' => 'desktop'", $layout);
        $this->assertStringContainsString("'navigationIdSuffix' => 'mobile'", $layout);
        $this->assertStringContainsString('sidebar-section-{{ $navigationIdSuffix }}-', $navigation);
    }

    public function test_mobile_styles_cover_touch_navigation_forms_tables_and_dialogs(): void
    {
        $publicPath = dirname(__DIR__, 2).'/public/css/pages';
        $themeStyles = (string) file_get_contents($publicPath.'/simplified-theme.css');
        $mobileStyles = (string) file_get_contents($publicPath.'/mobile.css');

        $this->assertStringContainsString("@import url('./mobile.css');", $themeStyles);
        $this->assertStringContainsString('@media (pointer: coarse)', $mobileStyles);
        $this->assertStringContainsString('.mobile-navigation__panel', $mobileStyles);
        $this->assertStringContainsString('.data-table__head', $mobileStyles);
        $this->assertStringContainsString('.wall-grid-builder', $mobileStyles);
        $this->assertStringContainsString('minmax(min(100%, 220px), 1fr)', $mobileStyles);
        $this->assertStringContainsString('.fleet-modal__panel', $mobileStyles);
        $this->assertStringContainsString('.player-sidebar > .screen-card', $mobileStyles);
        $this->assertStringContainsString('.player-sidebar .empty-state strong', $mobileStyles);
        $this->assertStringContainsString("font-size: 16px", $mobileStyles);
        $this->assertStringContainsString('env(safe-area-inset-bottom', $mobileStyles);
    }
}

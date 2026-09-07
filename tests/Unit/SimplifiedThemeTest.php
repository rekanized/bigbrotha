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

        preg_match_all('/@import url\([\'"]([^\'"]+)[\'"]\);/', $appStyles, $imports);
        $this->assertSame(['./pages/simplified-theme.css', './pages/mobile.css', './pages/live-wall.css'], $imports[1]);
        $this->assertStringNotContainsString("@import url('./mobile.css');", $themeStyles);
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

    public function test_operator_layout_exposes_one_global_header_with_responsive_navigation_and_a_skip_link(): void
    {
        $projectPath = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($projectPath.'/resources/views/layouts/app.blade.php');
        $header = (string) file_get_contents($projectPath.'/resources/views/layouts/partials/global-header.blade.php');
        $navigation = (string) file_get_contents($projectPath.'/resources/views/layouts/partials/primary-navigation.blade.php');

        $this->assertStringContainsString('class="skip-link"', $layout);
        $this->assertStringContainsString("@include('layouts.partials.global-header')", $layout);
        $this->assertStringContainsString('class="global-header"', $header);
        $this->assertStringContainsString('data-global-navigation', $header);
        $this->assertStringContainsString('id="main-content"', $layout);
        $this->assertStringContainsString("'navigationIdSuffix' => 'desktop'", $header);
        $this->assertStringContainsString("'navigationIdSuffix' => 'mobile'", $header);
        $this->assertStringContainsString('primary-navigation-section-{{ $navigationIdSuffix }}-', $navigation);
    }

    public function test_mobile_styles_cover_touch_navigation_forms_tables_and_dialogs(): void
    {
        $publicPath = dirname(__DIR__, 2).'/public/css/pages';
        $themeStyles = (string) file_get_contents($publicPath.'/simplified-theme.css');
        $mobileStyles = (string) file_get_contents($publicPath.'/mobile.css');
        $headerStyles = (string) file_get_contents(dirname($publicPath).'/components/global-header.css');

        $this->assertStringContainsString("@import url('./pages/mobile.css');", (string) file_get_contents(dirname($publicPath).'/app.css'));
        $this->assertStringContainsString("@import url('../components/global-header.css');", $themeStyles);
        $this->assertStringContainsString('@media (pointer: coarse)', $mobileStyles);
        $this->assertStringContainsString('.global-header__drawer-panel', $headerStyles);
        $this->assertStringContainsString('@media (max-width: 1180px)', $headerStyles);
        $this->assertStringContainsString('.data-table__head', $mobileStyles);
        $this->assertStringContainsString('.wall-grid-builder', $mobileStyles);
        $this->assertStringContainsString('minmax(min(100%, 220px), 1fr)', $mobileStyles);
        $this->assertStringContainsString('.fleet-modal__panel', $mobileStyles);
        $this->assertStringContainsString('.player-sidebar > .screen-card', $mobileStyles);
        $this->assertStringContainsString('.player-sidebar .empty-state strong', $mobileStyles);
        $this->assertStringContainsString("font-size: 16px", $mobileStyles);
        $this->assertStringContainsString('env(safe-area-inset-bottom', $mobileStyles);
    }

    public function test_every_authenticated_operator_entry_view_uses_the_global_application_layout(): void
    {
        $viewsPath = dirname(__DIR__, 2).'/resources/views';
        $operatorViews = [
            'admin/audit-logs.blade.php',
            'admin/settings.blade.php',
            'admin/users.blade.php',
            'camera-fleet/index.blade.php',
            'live-wall/index.blade.php',
            'live-wall/player.blade.php',
            'recordings/index.blade.php',
            'recordings/show.blade.php',
            'recordings/timeline.blade.php',
            'wall-tiles/index.blade.php',
        ];

        foreach ($operatorViews as $operatorView) {
            $contents = ltrim((string) file_get_contents($viewsPath.'/'.$operatorView));

            $this->assertStringStartsWith(
                "@extends('layouts.app')",
                $contents,
                $operatorView.' must inherit the global operator header.',
            );
        }

        $layout = (string) file_get_contents($viewsPath.'/layouts/app.blade.php');

        $this->assertSame(1, substr_count($layout, "@include('layouts.partials.global-header')"));
    }

    public function test_global_header_replaces_redundant_page_navigation_groups(): void
    {
        $viewsPath = dirname(__DIR__, 2).'/resources/views';
        $viewsWithoutPageNavigation = [
            'admin/audit-logs.blade.php',
            'admin/settings.blade.php',
            'admin/users.blade.php',
            'camera-fleet/index.blade.php',
            'recordings/index.blade.php',
            'wall-tiles/index.blade.php',
        ];

        foreach ($viewsWithoutPageNavigation as $view) {
            $contents = (string) file_get_contents($viewsPath.'/'.$view);

            $this->assertStringNotContainsString(
                "@section('page_actions')",
                $contents,
                $view.' must rely on the global header for cross-page navigation.',
            );
        }

        $recordingDetails = (string) file_get_contents($viewsPath.'/recordings/show.blade.php');
        $player = (string) file_get_contents($viewsPath.'/live-wall/player.blade.php');

        $this->assertStringContainsString('Back to browser', $recordingDetails);
        $this->assertStringNotContainsString("route('camera-fleet.index')", $recordingDetails);
        $this->assertStringContainsString('Back to wall', $player);
        $this->assertStringNotContainsString("route('camera-fleet.index')", $player);
    }
}

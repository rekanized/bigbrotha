<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class OperatorLayoutTest extends TestCase
{
    public function test_operator_layout_exposes_desktop_sidebar_mobile_header_and_a_skip_link(): void
    {
        $projectPath = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($projectPath.'/resources/views/layouts/app.blade.php');
        $header = (string) file_get_contents($projectPath.'/resources/views/layouts/partials/global-header.blade.php');
        $sidebar = (string) file_get_contents($projectPath.'/resources/views/layouts/partials/desktop-sidebar.blade.php');
        $navigation = (string) file_get_contents($projectPath.'/resources/views/layouts/partials/primary-navigation.blade.php');

        $this->assertStringContainsString('class="skip-link"', $layout);
        $this->assertStringContainsString("@include('layouts.partials.global-header')", $layout);
        $this->assertStringContainsString('class="global-header"', $header);
        $this->assertStringContainsString('data-global-navigation', $header);
        $this->assertStringContainsString('id="main-content"', $layout);
        $this->assertStringContainsString("@include('layouts.partials.desktop-sidebar')", $layout);
        $this->assertStringContainsString("'navigationIdSuffix' => 'desktop'", $sidebar);
        $this->assertStringContainsString("'navigationIdSuffix' => 'mobile'", $header);
        $this->assertStringContainsString('primary-navigation-section-{{ $navigationIdSuffix }}-', $navigation);
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
}

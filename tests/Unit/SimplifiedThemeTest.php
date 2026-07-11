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
}

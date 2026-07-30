<?php

declare(strict_types=1);

namespace Greatcode\OtpReader\Tests;

use Greatcode\OtpReader\Mail\OAuthUiRenderer;
use PHPUnit\Framework\TestCase;

class OAuthUiRendererTest extends TestCase
{
    public function testRenderRegisterFormHtml(): void
    {
        $html = OAuthUiRenderer::renderRegisterForm(
            successMessage: 'Account registered successfully!',
            errorMessage: null,
            authRedirectUrl: 'https://accounts.google.com/oauth'
        );

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Account registered successfully!', $html);
        $this->assertStringContainsString('https://accounts.google.com/oauth', $html);
        $this->assertStringContainsString('Register Google Account', $html);
    }
}

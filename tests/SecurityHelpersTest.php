<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Security\Html;
use Routlaw\Security\Csrf;

final class SecurityHelpersTest extends TestCase
{
    public function test_html_escapes_angle_brackets_and_quotes(): void
    {
        $in = '<script>alert("x")</script> & \'y\'';
        $out = Html::e($in);
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
        $this->assertStringContainsString('&apos;', $out);
        $this->assertStringContainsString('&amp;', $out);
    }

    public function test_csrf_token_is_stable_per_scope_and_verifiable(): void
    {
        $_SESSION = [];
        $t = Csrf::token('admin_section');
        $this->assertNotEmpty($t);
        $this->assertSame($t, Csrf::token('admin_section'), 'Token must be stable within a scope.');
        $this->assertTrue(Csrf::verify($t, 'admin_section'));
        $this->assertFalse(Csrf::verify('forged', 'admin_section'));
        $this->assertFalse(Csrf::verify($t, 'other_scope'));
    }

    public function test_csrf_verify_rejects_empty(): void
    {
        $this->assertFalse(Csrf::verify('', 'scope'));
    }
}

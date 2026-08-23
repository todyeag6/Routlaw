<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Agent\OutputSanitizer;

/**
 * T17 OutputSanitizer — HAR-14, LLM05, SEC-005/014.
 * Model and tool text is untrusted. The sanitizer encodes for HTML context and
 * neutralizes active markup (script / inline handlers / javascript: / data:) and
 * validates links before they reach a rendering or downstream surface. Safe text
 * passes through semantically unaltered.
 */
final class OutputSanitizerTest extends TestCase
{
    private OutputSanitizer $san;

    protected function setUp(): void
    {
        $this->san = new OutputSanitizer();
    }

    public function test_script_tag_is_neutralized(): void
    {
        $out = $this->san->sanitize('<script>alert(1)</script>Hello');
        $this->assertStringNotContainsString('<script', $out, 'Active script markup must be stripped.');
        $this->assertStringContainsString('Hello', $out, 'Safe text preserved.');
    }

    public function test_event_handler_attribute_is_neutralized(): void
    {
        $out = $this->san->sanitize('<img src=x onerror=alert(1)>');
        $this->assertStringNotContainsString('onerror', $out, 'Inline event handlers must be stripped.');
    }

    public function test_javascript_uri_is_neutralized(): void
    {
        $out = $this->san->sanitize('<a href="javascript:alert(1)">click</a>');
        $this->assertStringNotContainsString('javascript:', $out, 'javascript: URIs must be stripped.');
    }

    public function test_safe_text_is_encoded_but_semantics_preserved(): void
    {
        $text = 'Carrier #123 is approved for lane CHI->DAL. Rate: $1,250.';
        $out = $this->san->sanitize($text);
        $this->assertStringContainsString('CHI-&gt;DAL', $out, 'Special chars are HTML-encoded.');
        $this->assertStringNotContainsString('<', $out, 'No raw angle brackets survive in untrusted output.');
    }

    public function test_link_in_untrusted_text_is_encoded_not_rendered(): void
    {
        $out = $this->san->sanitize('<a href="https://example.com/doc">doc</a>');
        $this->assertStringContainsString('&lt;a href=&quot;https://example.com/doc&quot;&gt;', $out,
            'Link markup in untrusted text is encoded to inert text, not rendered live.');
        $this->assertStringNotContainsString('<a href', $out, 'No live anchor is emitted from untrusted output.');
    }

    public function test_marked_sanitized_flag_set(): void
    {
        $result = $this->san->sanitizeWithFlag('<script>x</script>ok');
        $this->assertTrue($result->sanitized, 'Output containing active markup must be flagged sanitized.');
        $this->assertStringContainsString('ok', $result->text);
        // Safe text yields sanitized=false.
        $clean = $this->san->sanitizeWithFlag('plain carrier note');
        $this->assertFalse($clean->sanitized, 'Clean text is not flagged sanitized.');
    }
}

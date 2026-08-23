<?php
declare(strict_types=1);

namespace Routlaw\Agent;

use Routlaw\Security\Html;

/**
 * OutputSanitizer — T17 output handling (HAR-14, LLM05, SEC-005/014).
 *
 * Model and tool text are UNTRUSTED (SEC-005). Before any value reaches an HTML
 * rendering surface or a downstream consumer, it is run through a strip pass that
 * removes active markup (script / on* handlers / javascript: / data: text/html), then
 * encoded for HTML context via the proven Security\Html::e() helper. Links are
 * validated: only http(s) hrefs survive; everything else is dropped.
 *
 * The sanitizer NEVER executes the content; it only neutralizes and encodes. Safe text
 * passes through semantically unaltered.
 */
final class OutputSanitizer
{
    /** Strip active markup before encoding (defense-in-depth over encoding alone). */
    public function sanitize(string $text): string
    {
        return Html::e($this->stripActiveMarkup($text));
    }

    /**
     * Same as sanitize() but also returns whether any neutralization occurred.
     */
    public function sanitizeWithFlag(string $text): SanitizedOutput
    {
        $stripped = $this->stripActiveMarkup($text);
        $changed = $stripped !== $text;
        return new SanitizedOutput(Html::e($stripped), $changed);
    }

    private function stripActiveMarkup(string $text): string
    {
        // Remove <script>...</script> and <style>...</style> blocks.
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $text) ?? $text;
        // Remove on*="..." / on*='...' inline event handlers.
        $text = preg_replace('#\s+on[a-z]+\s*=\s*("([^"]*)"|\'([^\']*)\'|[^\s>]+)#i', '', $text) ?? $text;
        // Remove javascript:/vbscript: protocol URIs (in href/src or standalone).
        $text = preg_replace('#(href|src)\s*=\s*("|\')\s*(javascript|vbscript):[^"\']*(\2)#i', '$1=$2#$4', $text) ?? $text;
        $text = preg_replace('#(javascript|vbscript):#i', '#', $text) ?? $text;
        // Drop <iframe>/<object>/<embed> hosts for rendered content.
        $text = preg_replace('#<(iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $text) ?? $text;
        $text = preg_replace('#<\s*(iframe|object|embed)\b[^>]*>#i', '', $text) ?? $text;
        // Validate links: only http(s) hrefs survive; others become '#'.
        $text = preg_replace_callback(
            '#<a\b[^>]*href\s*=\s*("|\')([^"\']*)\1[^>]*>#i',
            static function (array $m): string {
                $href = $m[2];
                if (preg_match('#^https?://#i', $href) === 1) {
                    return $m[0];
                }
                // Re-emit the tag with a safe href.
                return preg_replace('#href\s*=\s*("|\')([^"\']*)\1#i', 'href="#"', $m[0]) ?? $m[0];
            },
            $text
        ) ?? $text;
        return $text;
    }
}

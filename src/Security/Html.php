<?php
declare(strict_types=1);

namespace Routlaw\Security;

/** Contextual HTML output encoding (SEC-005: model text treated as untrusted). */
final class Html
{
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

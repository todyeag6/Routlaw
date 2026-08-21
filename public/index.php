<?php
declare(strict_types=1);

// Public entry probe for .htaccess + security-header verification (build-plan §3 T1.4).
require __DIR__ . '/../config/autoload.php';
use Routlaw\Security\Headers;
Headers::emit(false);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body><h1>ROUTLAW</h1><p>Foundation scaffold online.</p></body>
</html>

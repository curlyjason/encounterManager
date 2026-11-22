<?php
/**
 * Router for PHP's built-in web server (used by the start scripts):
 *   php -S 127.0.0.1:8765 router.php
 * Does what the .htaccess files do under Apache: keeps data/ and lib/ private.
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

if (preg_match('~(^|/)(data|lib|tools|dist)(/|$)|(^|/)\.|\.(sqlite|sqlite-\w+|md|sh|command|bat)$~i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
if ($path === '/' || $path === '') {
    require __DIR__ . '/index.php';
    return true;
}
return false; // let the built-in server handle real files (php, css, js)

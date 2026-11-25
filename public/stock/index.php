<?php
$host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
if ($host !== basename(__DIR__).'.laravel.demo') {
    http_response_code(404);
    exit;
}
define('LARAVEL_START', microtime(true));
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$app->handleRequest(Illuminate\Http\Request::capture());
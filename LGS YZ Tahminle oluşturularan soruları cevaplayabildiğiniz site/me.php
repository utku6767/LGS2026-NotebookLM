<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
session_boot();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = auth_user();

echo json_encode([
    'authenticated' => $user !== null,
    'user'          => $user,
], JSON_UNESCAPED_UNICODE);

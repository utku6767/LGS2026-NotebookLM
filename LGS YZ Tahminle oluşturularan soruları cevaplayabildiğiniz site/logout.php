<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
session_boot();

try {
    remember_forget();
} catch (Throwable $e) {
}

auth_logout();

header('Location: login.php?loggedout=1');
exit;

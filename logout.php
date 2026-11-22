<?php
require __DIR__ . '/auth.php';
session_destroy();
header('Location: ' . appUrl('login.php'));
exit;

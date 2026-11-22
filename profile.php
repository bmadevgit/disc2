<?php
require __DIR__ . '/auth.php';
requireLogin();
header('Location: ' . appUrl('index.php'));
exit;

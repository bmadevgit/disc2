<?php require __DIR__.'/auth.php';requireLogin(true);header('Location: '.appUrl('roles.php'));exit;

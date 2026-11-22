<?php
require __DIR__.'/auth.php'; requireLogin();
header('Location: '.appUrl('case.php?id='.(int)($_GET['id']??0))); exit;

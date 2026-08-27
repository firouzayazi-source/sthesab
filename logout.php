<?php
require_once __DIR__ . '/includes/auth.php';

Auth::initSession();
Auth::logout();

header('Location: login.php');
exit;
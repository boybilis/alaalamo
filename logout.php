<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

start_app_session();
session_destroy();

redirect_to('/login.php');


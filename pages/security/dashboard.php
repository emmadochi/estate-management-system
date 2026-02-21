<?php
require_once __DIR__ . '/../../app/bootstrap.php';
require_login(['security']);
header('Location: index.php', true, 302);
exit;

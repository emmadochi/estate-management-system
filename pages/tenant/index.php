<?php
require_once __DIR__ . '/../../app/bootstrap.php';

require_login(['tenant']);
redirect('dashboard.php');

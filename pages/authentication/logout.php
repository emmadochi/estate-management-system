<?php
require_once __DIR__ . '/../../app/bootstrap.php';

if (current_user()) {
    audit_log('logout', 'user', current_user_id(), null, null);
}
logout_user();
redirect(app_url('pages/authentication/layouts/corporate/sign-in.php'));


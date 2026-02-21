<?php
require_once __DIR__ . '/../../../../app/bootstrap.php';

$error = null;

if (request_method() === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string)post_param('email', '')));
    $password = (string)post_param('password', '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Please enter a valid email and password.';
    } else {
        try {
            $db = db();
            $user = $db->fetchOne('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
            if (!$user || ($user['status'] ?? '') !== 'active') {
                $error = 'Invalid credentials.';
            } elseif (!password_verify($password, $user['password'])) {
                $error = 'Invalid credentials.';
            } else {
                login_user($user);
                audit_log('login', 'user', (int)$user['id'], ['email' => $email, 'role' => $user['role'] ?? null], null);
                $return = (string)get_param('return', '');
                if ($return !== '') {
                    redirect($return);
                }
                redirect_after_login($user['role'] ?? '');
            }
        } catch (Throwable $e) {
            $error = 'Login failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>Sign In – EstatePro</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="shortcut icon" href="../../../assets/media/logos/favicon.ico">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
        <link href="../../../assets/plugins/global/plugins.bundle.css" rel="stylesheet" type="text/css">
        <link href="../../../assets/css/style.bundle.css" rel="stylesheet" type="text/css">
    </head>
    <body id="kt_body" class="app-blank app-blank">
        <script>
            var defaultThemeMode = "light";
            var themeMode;
            if (document.documentElement) {
                if (document.documentElement.hasAttribute("data-bs-theme-mode")) {
                    themeMode = document.documentElement.getAttribute("data-bs-theme-mode");
                } else if (localStorage.getItem("data-bs-theme") !== null) {
                    themeMode = localStorage.getItem("data-bs-theme");
                } else {
                    themeMode = defaultThemeMode;
                }
                if (themeMode === "system") {
                    themeMode = window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
                }
                document.documentElement.setAttribute("data-bs-theme", themeMode);
            }
        </script>

        <div class="d-flex flex-column flex-root" id="kt_app_root">
            <div class="d-flex flex-column flex-lg-row flex-column-fluid">
                <div class="d-flex flex-lg-row-fluid w-lg-50 bgi-size-cover bgi-position-center" style="background-image: url(../../../assets/media/misc/auth-bg.png)">
                    <div class="d-flex flex-column flex-center p-6 p-lg-10 w-100">
                        <a href="../../../index.html" class="mb-0 mb-lg-20">
                            <img alt="Logo" src="../../../assets/media/logos/default-white.svg" class="h-40px h-lg-50px">
                        </a>
                        <img class="d-none d-lg-block mx-auto w-300px w-lg-75 w-xl-500px mb-10 mb-lg-20" src="../../../assets/media/misc/auth-screens.png" alt="">
                        <h1 class="d-none d-lg-block text-white fs-2qx fw-bold text-center mb-7">
                            EstatePro Admin Access
                        </h1>
                        <div class="d-none d-lg-block text-white fs-base text-center">
                            Securely manage estates, units, tenants, rent and maintenance in one place.
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-column flex-lg-row-fluid w-lg-50 p-10">
                    <div class="d-flex flex-center flex-column flex-lg-row-fluid">
                        <div class="w-lg-500px p-10">
                            <form class="form w-100" method="post" action="">
                                <?= csrf_field() ?>
                                <div class="text-center mb-11">
                                    <h1 class="text-gray-900 fw-bolder mb-3">
                                        Sign In
                                    </h1>
                                    <div class="text-gray-500 fw-semibold fs-6">
                                        Use your EstatePro admin or tenant account.
                                    </div>
                                </div>

                                <?php if ($error): ?>
                                    <div class="alert alert-danger d-flex align-items-center mb-6" role="alert">
                                        <span class="svg-icon svg-icon-2hx svg-icon-danger me-3">
                                            <i class="ki-duotone ki-shield-cross fs-2 text-danger"></i>
                                        </span>
                                        <div class="d-flex flex-column">
                                            <span class="fw-bold"><?= e($error) ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="fv-row mb-8">
                                    <input type="text" placeholder="Email" name="email" autocomplete="off" class="form-control bg-transparent" required>
                                </div>

                                <div class="fv-row mb-3">
                                    <input type="password" placeholder="Password" name="password" autocomplete="off" class="form-control bg-transparent" required>
                                </div>

                                <div class="d-flex flex-stack flex-wrap gap-3 fs-base fw-semibold mb-8">
                                    <div></div>
                                    <a href="#" class="link-primary">Forgot Password?</a>
                                </div>

                                <div class="d-grid mb-10">
                                    <button type="submit" class="btn btn-primary">
                                        <span class="indicator-label">Sign In</span>
                                    </button>
                                </div>

                                <div class="text-center fw-semibold text-gray-500 fs-6">
                                    Default Super Admin:
                                    <span class="d-block mt-1">
                                        <code>admin@estatepro.com</code> / <code>password</code>
                                    </span>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="d-flex flex-center flex-wrap px-10">
                        <div class="d-flex fw-semibold text-primary fs-base">
                            <a href="../../../index.html" class="px-5">Back to landing</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script src="../../../assets/plugins/global/plugins.bundle.js"></script>
        <script src="../../../assets/js/scripts.bundle.js"></script>
    </body>
</html>


<?php
if (!isset($pageTitle) || trim((string)$pageTitle) === '') {
    $pageTitle = 'EstatePro Tenant';
}
$flash = function_exists('flash_get') ? flash_get() : null;
$me = function_exists('current_user') ? current_user() : null;
$tenant = function_exists('current_tenant') ? current_tenant() : null;
$pageHeading = $pageHeading ?? preg_replace('/\s+–\s+EstatePro.*/u', '', (string)$pageTitle);
$current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
if (function_exists('mark_notification_read_if_requested')) {
    mark_notification_read_if_requested();
}
$notifications = function_exists('get_notifications_for_current_user') ? get_notifications_for_current_user(15) : [];
$unreadNotificationCount = function_exists('get_unread_notification_count') ? get_unread_notification_count() : 0;

function _tenant_nav_active(string $file, string $current): string {
    return $file === $current ? ' active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title><?= e($pageTitle) ?></title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="canonical" href="dashboard.php">
        <link rel="shortcut icon" href="../assets/media/logos/favicon.ico">

        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
        <link href="../assets/plugins/global/plugins.bundle.css" rel="stylesheet" type="text/css">
        <link href="../assets/css/style.bundle.css" rel="stylesheet" type="text/css">
    </head>

    <body id="kt_app_body"
          data-kt-app-layout="dark-sidebar"
          data-kt-app-header-fixed="true"
          data-kt-app-sidebar-enabled="true"
          data-kt-app-sidebar-fixed="true"
          data-kt-app-sidebar-hoverable="true"
          data-kt-app-sidebar-push-header="true"
          data-kt-app-sidebar-push-toolbar="true"
          data-kt-app-sidebar-push-footer="true"
          data-kt-app-toolbar-enabled="true"
          class="app-default">

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

        <div class="d-flex flex-column flex-root app-root" id="kt_app_root">
            <div class="app-page flex-column flex-column-fluid" id="kt_app_page">

                <div id="kt_app_header" class="app-header">
                    <div class="app-container container-fluid d-flex align-items-stretch justify-content-between" id="kt_app_header_container">
                        <div class="d-flex align-items-center d-lg-none ms-n3 me-2" title="Show sidebar menu">
                            <div class="btn btn-icon btn-active-color-primary w-35px h-35px" id="kt_app_sidebar_mobile_toggle">
                                <i class="ki-duotone ki-abstract-14 fs-1"><span class="path1"></span><span class="path2"></span></i>
                            </div>
                        </div>

                        <div class="d-flex align-items-center flex-grow-1 flex-lg-grow-0">
                            <a href="dashboard.php" class="d-lg-none">
                                <img alt="Logo" src="../assets/media/logos/default-small.svg" class="theme-light-show h-30px">
                                <img alt="Logo" src="../assets/media/logos/default-small-dark.svg" class="theme-dark-show h-30px">
                            </a>
                        </div>

                        <div class="d-flex align-items-stretch justify-content-between flex-lg-grow-1" id="kt_app_header_wrapper">
                            <div class="app-header-menu app-header-mobile-drawer align-items-stretch"
                                 data-kt-drawer="true" data-kt-drawer-name="app-header-menu" data-kt-drawer-activate="{default: true, lg: false}"
                                 data-kt-drawer-overlay="true" data-kt-drawer-width="225px" data-kt-drawer-direction="end"
                                 data-kt-drawer-toggle="#kt_app_header_menu_toggle"
                                 data-kt-swapper="true" data-kt-swapper-mode="{default: 'append', lg: 'prepend'}"
                                 data-kt-swapper-parent="{default: '#kt_app_body', lg: '#kt_app_header_wrapper'}">
                                <div class="menu menu-rounded menu-column menu-lg-row my-5 my-lg-0 align-items-stretch fw-semibold px-2 px-lg-0" id="kt_app_header_menu" data-kt-menu="true">
                                    <div class="menu-item here show menu-here-bg menu-lg-down-accordion me-0 me-lg-2">
                                        <a class="menu-link" href="dashboard.php"><span class="menu-title">Tenant Portal</span></a>
                                    </div>
                                </div>
                            </div>

                            <div class="app-navbar flex-shrink-0 d-flex align-items-center">
                                <div class="app-navbar-item ms-2">
                                    <button type="button" class="btn btn-icon btn-active-color-primary w-35px h-35px" id="kt_theme_toggle" title="Toggle dark/light mode" aria-label="Toggle theme">
                                        <i class="bi bi-moon-stars fs-2" id="kt_theme_icon_moon" title="Switch to dark mode"></i>
                                        <i class="bi bi-sun fs-2" id="kt_theme_icon_sun" style="display:none;" title="Switch to light mode"></i>
                                    </button>
                                </div>
                                <?php if ($me): ?>
                                    <div class="app-navbar-item ms-2" id="kt_header_notifications_toggle">
                                        <div class="btn btn-icon btn-active-color-primary position-relative w-35px h-35px"
                                             data-kt-menu-trigger="{default: 'click', lg: 'click'}"
                                             data-kt-menu-attach="parent"
                                             data-kt-menu-placement="bottom-end">
                                            <i class="ki-duotone ki-notification-on fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                                            <?php if ($unreadNotificationCount > 0): ?>
                                            <span class="position-absolute top-0 start-100 translate-middle badge badge-circle badge-danger w-15px h-15px fs-9"><?= $unreadNotificationCount > 9 ? '9+' : $unreadNotificationCount ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="menu menu-sub menu-sub-dropdown menu-column w-350px w-lg-375px" data-kt-menu="true">
                                            <div class="d-flex flex-column bgi-no-repeat rounded-top px-9 py-7" style="background-color: #1e1e2d;">
                                                <h3 class="text-white fw-bold mb-3">Notifications</h3>
                                                <?php if ($unreadNotificationCount > 0): ?>
                                                <a href="notifications.php?mark_all=1" class="btn btn-sm btn-light-primary w-fit">Mark all as read</a>
                                                <?php endif; ?>
                                            </div>
                                            <div class="scroll-y mh-325px my-5 px-8">
                                                <?php if (empty($notifications)): ?>
                                                <div class="text-gray-500 text-center py-6">No notifications yet.</div>
                                                <?php else: ?>
                                                <?php foreach ($notifications as $n): ?>
                                                <?php $nUrl = !empty($n['link']) ? $n['link'] . (strpos((string)$n['link'], '?') !== false ? '&' : '?') . 'nid=' . (int)$n['id'] : '#'; ?>
                                                <a href="<?= e($nUrl) ?>" class="d-flex flex-column mb-5 <?= empty($n['read_at']) ? 'bg-light-primary' : '' ?> rounded p-4 text-gray-800 text-hover-primary">
                                                    <span class="fw-semibold"><?= e($n['title']) ?></span>
                                                    <?php if (!empty($n['body'])): ?><span class="fs-7 text-muted"><?= e(mb_strimwidth($n['body'], 0, 80, '…')) ?></span><?php endif; ?>
                                                    <span class="fs-8 text-gray-500 mt-1"><?= e(date('M j, g:i A', strtotime($n['created_at']))) ?></span>
                                                </a>
                                                <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="py-3 text-center border-top">
                                                <a href="notifications.php" class="btn btn-sm btn-light">View all</a>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="app-navbar-item ms-2" id="kt_header_user_menu_toggle">
                                        <div class="cursor-pointer symbol symbol-35px"
                                             data-kt-menu-trigger="{default: 'click', lg: 'hover'}"
                                             data-kt-menu-attach="parent"
                                             data-kt-menu-placement="bottom-end">
                                            <?php $userAvatar = $me['avatar'] ?? null; $hasAvatar = function_exists('user_has_avatar') && user_has_avatar($userAvatar); ?>
                                            <?php if ($hasAvatar): ?>
                                                <img src="<?= e(get_avatar_url($userAvatar)) ?>" alt="user">
                                            <?php else: ?>
                                                <div class="symbol-label bg-light-primary">
                                                    <i class="ki-duotone ki-user fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-800 menu-state-bg menu-state-primary fw-semibold py-4 fs-6 w-275px" data-kt-menu="true">
                                            <div class="menu-item px-3">
                                                <div class="menu-content d-flex align-items-center px-3">
                                                    <div class="symbol symbol-50px me-5">
                                                        <?php if ($hasAvatar): ?>
                                                            <img alt="user" src="<?= e(get_avatar_url($userAvatar)) ?>">
                                                        <?php else: ?>
                                                            <div class="symbol-label bg-light-primary w-100 h-100 d-flex align-items-center justify-content-center">
                                                                <i class="ki-duotone ki-user fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="d-flex flex-column">
                                                        <div class="fw-bold d-flex align-items-center fs-5">
                                                            <?= e(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?>
                                                        </div>
                                                        <span class="fw-semibold text-muted fs-7"><?= e($me['email'] ?? '') ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="separator my-2"></div>
                                            <div class="menu-item px-5">
                                                <a href="profile.php" class="menu-link px-5">My Profile</a>
                                            </div>
                                            <div class="menu-item px-5">
                                                <a href="../authentication/logout.php" class="menu-link px-5">Sign Out</a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="app-wrapper flex-column flex-row-fluid" id="kt_app_wrapper">
                    <div id="kt_app_sidebar" class="app-sidebar flex-column"
                         data-kt-drawer="true" data-kt-drawer-name="app-sidebar"
                         data-kt-drawer-activate="{default: true, lg: false}"
                         data-kt-drawer-overlay="true" data-kt-drawer-width="225px"
                         data-kt-drawer-direction="start"
                         data-kt-drawer-toggle="#kt_app_sidebar_mobile_toggle">

                        <div class="app-sidebar-logo px-6" id="kt_app_sidebar_logo">
                            <a href="dashboard.php">
                                <img alt="Logo" src="../assets/media/logos/default-dark.svg" class="h-30px app-sidebar-logo-default">
                            </a>
                            <div id="kt_app_sidebar_toggle" class="app-sidebar-toggle btn btn-icon btn-sm h-30px w-30px rotate"
                                 data-kt-toggle="true" data-kt-toggle-state="active" data-kt-toggle-target="body" data-kt-toggle-name="app-sidebar-minimize">
                                <i class="ki-duotone ki-double-left fs-2 rotate-180"><span class="path1"></span><span class="path2"></span></i>
                            </div>
                        </div>

                        <div class="app-sidebar-menu overflow-hidden flex-column-fluid">
                            <div id="kt_app_sidebar_menu_wrapper" class="app-sidebar-wrapper">
                                <div id="kt_app_sidebar_menu_scroll" class="hover-scroll-y my-5 mx-3"
                                     data-kt-scroll="true" data-kt-scroll-activate="true" data-kt-scroll-height="auto"
                                     data-kt-scroll-dependencies="#kt_app_sidebar_logo, #kt_app_sidebar_footer"
                                     data-kt-scroll-wrappers="#kt_app_sidebar_menu"
                                     data-kt-scroll-offset="5px"
                                     data-kt-scroll-save-state="true">
                                    <div class="menu menu-column menu-rounded menu-sub-indention fw-semibold" id="kt_app_sidebar_menu" data-kt-menu="true" data-kt-menu-expand="false">

                                        <div class="menu-item">
                                            <a class="menu-link<?= _tenant_nav_active('dashboard.php', $current) ?>" href="dashboard.php">
                                                <span class="menu-icon"><i class="ki-duotone ki-home-1 fs-2"><span class="path1"></span><span class="path2"></span></i></span>
                                                <span class="menu-title">Dashboard</span>
                                            </a>
                                        </div>

                                        <div class="menu-item pt-5">
                                            <div class="menu-content"><span class="menu-heading fw-bold text-uppercase fs-7">My Home</span></div>
                                        </div>
                                        <div class="menu-item">
                                            <a class="menu-link<?= _tenant_nav_active('leases.php', $current) ?>" href="leases.php">
                                                <span class="menu-icon"><i class="ki-duotone ki-document fs-2"><span class="path1"></span><span class="path2"></span></i></span>
                                                <span class="menu-title">My Lease</span>
                                            </a>
                                        </div>
                                        <div class="menu-item">
                                            <a class="menu-link<?= _tenant_nav_active('lease_requests.php', $current) ?>" href="lease_requests.php">
                                                <span class="menu-icon"><i class="ki-duotone ki-chart-simple fs-2"><span class="path1"></span><span class="path2"></span></i></span>
                                                <span class="menu-title">Lease Requests</span>
                                            </a>
                                        </div>
                                        <div class="menu-item">
                                            <a class="menu-link<?= _tenant_nav_active('invoices.php', $current) ?>" href="invoices.php">
                                                <span class="menu-icon"><i class="ki-duotone ki-bill fs-2"><span class="path1"></span><span class="path2"></span></i></span>
                                                <span class="menu-title">Rent & Bills</span>
                                            </a>
                                        </div>
                                        <div class="menu-item">
                                            <a class="menu-link<?= _tenant_nav_active('payments.php', $current) ?>" href="payments.php">
                                                <span class="menu-icon"><i class="ki-duotone ki-credit-cart fs-2"><span class="path1"></span><span class="path2"></span></i></span>
                                                <span class="menu-title">Payment History</span>
                                            </a>
                                        </div>

                                        <div class="menu-item pt-5">
                                            <div class="menu-content"><span class="menu-heading fw-bold text-uppercase fs-7">Support</span></div>
                                        </div>
                                        <div class="menu-item">
                                            <a class="menu-link<?= _tenant_nav_active('maintenance.php', $current) ?>" href="maintenance.php">
                                                <span class="menu-icon"><i class="ki-duotone ki-setting-2 fs-2"><span class="path1"></span><span class="path2"></span></i></span>
                                                <span class="menu-title">Maintenance Tickets</span>
                                            </a>
                                        </div>
                                        <div class="menu-item">
                                            <a class="menu-link<?= _tenant_nav_active('emergency_alert_pro.php', $current) ?>" href="emergency_alert_pro.php">
                                                <span class="menu-icon"><i class="ki-duotone ki-siren fs-2"><span class="path1"></span><span class="path2"></span></i></span>
                                                <span class="menu-title">Emergency Alert</span>
                                            </a>
                                        </div>
                                        <div class="menu-item">
                                            <a class="menu-link<?= _tenant_nav_active('announcements.php', $current) ?>" href="announcements.php">
                                                <span class="menu-icon"><i class="ki-duotone ki-notification-on fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i></span>
                                                <span class="menu-title">Announcements</span>
                                            </a>
                                        </div>

                                        <div class="menu-item pt-5">
                                            <div class="menu-content"><span class="menu-heading fw-bold text-uppercase fs-7">Account</span></div>
                                        </div>
                                        <div class="menu-item">
                                            <a class="menu-link<?= _tenant_nav_active('profile.php', $current) ?>" href="profile.php">
                                                <span class="menu-icon"><i class="ki-duotone ki-user fs-2"><span class="path1"></span><span class="path2"></span></i></span>
                                                <span class="menu-title">Profile & Security</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="app-main flex-column flex-row-fluid" id="kt_app_main">
                        <div class="d-flex flex-column flex-column-fluid">

                            <div id="kt_app_toolbar" class="app-toolbar py-3 py-lg-6">
                                <div id="kt_app_toolbar_container" class="app-container container-fluid d-flex flex-stack">
                                    <div class="page-title d-flex flex-column justify-content-center flex-wrap me-3">
                                        <h1 class="page-heading d-flex text-gray-900 fw-bold fs-3 flex-column justify-content-center my-0"><?= e($pageHeading) ?></h1>
                                        <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
                                            <li class="breadcrumb-item text-muted"><a href="dashboard.php" class="text-muted text-hover-primary">Home</a></li>
                                            <li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
                                            <li class="breadcrumb-item text-muted"><?= e($pageHeading) ?></li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div id="kt_app_content" class="app-content flex-column-fluid">
                                <div id="kt_app_content_container" class="app-container container-fluid">
                                    <?php if ($flash): ?>
                                        <?php
                                            $type = $flash['type'] ?? 'info';
                                            $message = $flash['message'] ?? '';
                                            $alert = 'alert-info';
                                            if ($type === 'success') $alert = 'alert-success';
                                            if ($type === 'error') $alert = 'alert-danger';
                                            if ($type === 'warning') $alert = 'alert-warning';
                                        ?>
                                        <div class="alert <?= e($alert) ?> d-flex align-items-center" role="alert">
                                            <div class="flex-grow-1"><?= e($message) ?></div>
                                        </div>
                                    <?php endif; ?>

<?php
if (!isset($pageTitle) || trim((string)$pageTitle) === '') {
    $pageTitle = 'Security – EstatePro';
}
$flash = function_exists('flash_get') ? flash_get() : null;
$pageHeading = $pageHeading ?? preg_replace('/\s+–\s+EstatePro.*/u', '', (string)$pageTitle);
$current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$me = current_user();
$estates = function_exists('estates_for_current_user') ? estates_for_current_user() : [];
$estateName = !empty($estates) ? ($estates[0]['name'] ?? '') : '';

function _security_nav_active(string $file, string $current): string {
    return $file === $current ? ' active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <title><?= e($pageTitle) ?></title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="shortcut icon" href="../../assets/media/logos/favicon.ico">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
    <link href="../../assets/plugins/global/plugins.bundle.css" rel="stylesheet" type="text/css">
    <link href="../../assets/css/style.bundle.css" rel="stylesheet" type="text/css">
    <link rel="stylesheet" href="../../assets/plugins/custom/datatables/datatables.bundle.css">
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
              <a href="index.php" class="d-lg-none">
                <img alt="Logo" src="../../assets/media/logos/default-small.svg" class="theme-light-show h-30px">
                <img alt="Logo" src="../../assets/media/logos/default-small-dark.svg" class="theme-dark-show h-30px">
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
                    <a class="menu-link" href="index.php"><span class="menu-title">Security Area</span></a>
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
                <div class="app-navbar-item ms-2">
                  <a href="../authentication/logout.php" class="btn btn-sm btn-light">Sign Out</a>
                </div>
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
              <a href="index.php">
                <img alt="Logo" src="../../assets/media/logos/default-dark.svg" class="h-30px app-sidebar-logo-default">
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
                     data-kt-scroll-dependencies="#kt_app_sidebar_logo"
                     data-kt-scroll-wrappers="#kt_app_sidebar_menu"
                     data-kt-scroll-offset="5px"
                     data-kt-scroll-save-state="true">
                  <div class="menu menu-column menu-rounded menu-sub-indention fw-semibold" id="kt_app_sidebar_menu" data-kt-menu="true" data-kt-menu-expand="false">
                    <div class="menu-item">
                      <a class="menu-link<?= _security_nav_active('index.php', $current) ?>" href="index.php">
                        <span class="menu-icon"><i class="ki-duotone ki-category fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i></span>
                        <span class="menu-title">Dashboard</span>
                      </a>
                    </div>
                    <div class="menu-item pt-5">
                      <div class="menu-content"><span class="menu-heading fw-bold text-uppercase fs-7">Security</span></div>
                    </div>
                    <div class="menu-item">
                      <a class="menu-link<?= _security_nav_active('visitor_logs.php', $current) ?>" href="visitor_logs.php">
                        <span class="menu-icon"><i class="ki-duotone ki-profile-user fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i></span>
                        <span class="menu-title">Visitor Logs</span>
                      </a>
                    </div>
                    <div class="menu-item">
                      <a class="menu-link<?= _security_nav_active('gate_passes.php', $current) ?>" href="gate_passes.php">
                        <span class="menu-icon"><i class="ki-duotone ki-key fs-2"><span class="path1"></span><span class="path2"></span></i></span>
                        <span class="menu-title">Gate Passes</span>
                      </a>
                    </div>
                    <div class="menu-item">
                      <a class="menu-link<?= _security_nav_active('emergency_response.php', $current) ?>" href="emergency_response.php">
                        <span class="menu-icon"><i class="ki-duotone ki-siren fs-2"><span class="path1"></span><span class="path2"></span></i></span>
                        <span class="menu-title">Emergency Alerts</span>
                      </a>
                    </div>
                    <div class="menu-item">
                      <a class="menu-link<?= _security_nav_active('emergency_incidents.php', $current) ?>" href="emergency_incidents.php">
                        <span class="menu-icon"><i class="ki-duotone ki-information fs-2"><span class="path1"></span><span class="path2"></span></i></span>
                        <span class="menu-title">Emergency Incidents</span>
                      </a>
                    </div>
                    <div class="menu-item">
                      <a class="menu-link<?= _security_nav_active('patrol_logs.php', $current) ?>" href="patrol_logs.php">
                        <span class="menu-icon"><i class="ki-duotone ki-route fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i></span>
                        <span class="menu-title">Patrol Logs</span>
                      </a>
                    </div>
                    <div class="menu-item">
                      <a class="menu-link<?= _security_nav_active('incident_reports.php', $current) ?>" href="incident_reports.php">
                        <span class="menu-icon"><i class="ki-duotone ki-file fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i></span>
                        <span class="menu-title">Incident Reports</span>
                      </a>
                    </div>
                    <div class="menu-item">
                      <a class="menu-link<?= _security_nav_active('security_profile.php', $current) ?>" href="security_profile.php">
                        <span class="menu-icon"><i class="ki-duotone ki-user fs-2"><span class="path1"></span><span class="path2"></span></i></span>
                        <span class="menu-title">My Profile</span>
                      </a>
                    </div>
                    <div class="menu-item">
                      <a class="menu-link<?= _security_nav_active('attendance.php', $current) ?>" href="attendance.php">
                        <span class="menu-icon"><i class="ki-duotone ki-calendar fs-2"><span class="path1"></span><span class="path2"></span></i></span>
                        <span class="menu-title">Attendance</span>
                      </a>
                    </div>
                    <div class="separator my-4"></div>
                    <div class="menu-item px-3">
                      <div class="text-gray-600 fs-8">Security</div>
                      <div class="fw-bold text-gray-900"><?= e(trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''))) ?: 'Officer' ?></div>
                      <?php if ($estateName): ?><div class="text-gray-600 fs-8"><?= e($estateName) ?></div><?php endif; ?>
                      <?php if (!empty($me['email'])): ?><div class="text-gray-600 fs-8"><?= e($me['email']) ?></div><?php endif; ?>
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
                      <li class="breadcrumb-item text-muted"><a href="index.php" class="text-muted text-hover-primary">Home</a></li>
                      <li class="breadcrumb-item"><span class="bullet bg-gray-500 w-5px h-2px"></span></li>
                      <li class="breadcrumb-item text-muted"><?= e($pageHeading) ?></li>
                    </ul>
                  </div>
                  <?php if (!empty($toolbarActions)): ?>
                  <div class="d-flex align-items-center gap-2"><?= $toolbarActions ?></div>
                  <?php endif; ?>
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

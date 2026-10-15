<?php
// shared top part of every logged-in page (sidebar + topbar)
// set $page_title and $active before including this
$u = current_user();
$page_title = $page_title ?? 'KALASAG';
$active = $active ?? '';
$extra_head = $extra_head ?? '';

// sidebar links per role: key, label, icon, page
$nav = [
    'admin' => [
        ['dashboard', 'Dashboard',           'bi-grid-1x2-fill',         'admin/dashboard.php'],
        ['incidents', 'Incidents',           'bi-exclamation-octagon',   'admin/incidents.php'],
        ['responders','Responders',          'bi-people-fill',           'admin/responders.php'],
        ['residents', 'Residents & households','bi-house-heart-fill',    'admin/residents.php'],
        ['announce',  'Announcements',       'bi-megaphone-fill',        'admin/announcements.php'],
        ['evac',      'Evacuation centers',  'bi-building-fill-check',   'admin/evacuation.php'],
        ['analytics', 'Analytics & reports', 'bi-bar-chart-line-fill',   'admin/analytics.php'],
    ],
    'resident' => [
        ['home',      'Home',                'bi-house-door-fill',       'resident/home.php'],
        ['report',    'Report an emergency', 'bi-broadcast',             'resident/report.php'],
        ['myreports', 'My reports',          'bi-card-checklist',        'resident/reports.php'],
        ['household', 'My household',        'bi-house-heart-fill',      'resident/household.php'],
        ['evac',      'Evacuation centers',  'bi-building-fill-check',   'resident/evacuation.php'],
    ],
    'responder' => [
        ['home',      'My assignments',      'bi-truck-front-fill',      'responder/home.php'],
    ],
];
$links = $nav[$u['role']] ?? [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1C110B">
<link rel="icon" type="image/svg+xml" href="<?= url('assets/img/favicon.svg') ?>">
<title><?= e($page_title) ?> | KALASAG</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?= $extra_head ?>
<link href="<?= url('assets/css/kalasag.css') ?>" rel="stylesheet">
</head>
<body class="app">

<aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-label="Main menu">
    <div class="sidebar-brand">
        <!-- logo + name go back to your home page -->
        <a class="brand-link" href="<?= url(home_for($u['role'])) ?>" aria-label="KALASAG home">
            <img class="shield" src="<?= url('assets/img/logo-mark.svg') ?>" alt="">
            <div>
                <div class="brand-name">KALASAG</div>
                <div class="brand-sub">Barangay 172, Zone 17</div>
            </div>
        </a>
        <button type="button" class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close menu"></button>
    </div>
    <div class="carved" aria-hidden="true"></div>
    <nav class="sidebar-nav">
        <?php foreach ($links as [$key, $label, $icon, $href]): ?>
            <a href="<?= url($href) ?>" class="nav-item <?= $active === $key ? 'active' : '' ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>>
                <i class="bi <?= $icon ?>"></i><span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
        <a class="hotline <?= $active === 'hotlines' ? 'active' : '' ?>" href="<?= url('hotlines.php') ?>">
            <span><i class="bi bi-telephone-fill me-2"></i>Emergency hotlines</span>
            <i class="bi bi-chevron-right"></i>
        </a>
    </div>
</aside>

<div class="main">
    <header class="topbar">
        <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Open menu">
            <i class="bi bi-list"></i>
        </button>
        <!-- small logo on phones since the sidebar is hidden there, also goes home -->
        <a class="topbar-logo d-lg-none" href="<?= url(home_for($u['role'])) ?>" aria-label="KALASAG home">
            <img src="<?= url('assets/img/logo-mark.svg') ?>" alt="">
        </a>
        <h1 class="page-title"><?= e($page_title) ?></h1>
        <div class="topbar-right">
            <span class="clock d-none d-md-inline" id="clock"></span>
            <div class="dropdown">
                <button class="user-chip dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="avatar"><?= e(strtoupper(substr($u['full_name'], 0, 1))) ?></span>
                    <span class="d-none d-sm-inline"><?= e($u['full_name']) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text small text-secondary">Signed in as <?= e(ucfirst($u['role'])) ?></span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= url('account.php') ?>"><i class="bi bi-person-gear me-2"></i>Account settings</a></li>
                    <li><a class="dropdown-item" href="<?= url('logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>Log out</a></li>
                </ul>
            </div>
        </div>
    </header>
    <main class="content">
        <?php show_flash(); ?>

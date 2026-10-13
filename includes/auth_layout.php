<?php
// left dark side of the login/register pages, shared so we dont copy paste
function auth_top($title, $extra_head = '', $wide = false) { ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1C110B">
<link rel="icon" type="image/svg+xml" href="<?= url('assets/img/favicon.svg') ?>">
<title><?= e($title) ?> | KALASAG</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?= $extra_head ?>
<link href="<?= url('assets/css/kalasag.css') ?>" rel="stylesheet">
</head>
<body>
<div class="auth">
    <section class="auth-side">
        <div class="carved carved-top" aria-hidden="true"></div>
        <div class="auth-brand">
            <span>Barangay 172, Zone 17, Malibay, Pasay City</span>
        </div>
        <div class="auth-hero">
            <!-- logo + name both go home (index.php sends you to login or your dashboard) -->
            <!-- logo link is skipped by keyboard/screen readers so it isnt read twice -->
            <a class="auth-home" href="<?= url('index.php') ?>" tabindex="-1" aria-hidden="true">
                <img class="auth-logo" src="<?= url('assets/img/logo.svg') ?>" alt="">
            </a>
            <div>
            <a class="auth-home" href="<?= url('index.php') ?>" aria-label="KALASAG home"><p class="auth-word">KALASAG</p></a>
            <div class="auth-tag">Tanggulan ng Barangay</div>
            <p class="auth-desc">Report floods, fires, accidents, and medical emergencies straight to the barangay, and see when help is on the way.</p>
            </div>
        </div>
        <div class="auth-foot">For life-threatening emergencies, contact the barangay or the official emergency hotlines right away. <a href="<?= url('hotlines.php') ?>">See emergency hotlines</a></div>
    </section>
    <main class="auth-main">
        <div class="auth-form<?= $wide ? ' wide' : '' ?>">
<?php }

function auth_bottom($extra_scripts = '') { ?>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= url('assets/js/kalasag.js') ?>"></script>
<?= $extra_scripts ?>
</body>
</html>
<?php }

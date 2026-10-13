<?php
// report without an account, so nobody gets slowed down during an emergency
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/incidents.php';
require_once __DIR__ . '/includes/report_form.php';
require_once __DIR__ . '/includes/auth_layout.php';

// logged in residents use the normal form so the report is linked to them
if (current_user() && current_user()['role'] === 'resident') redirect('resident/report.php');

$types = incident_types($conn);
$d = report_defaults();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // basic spam guard: one guest report every 2 minutes per browser
    if (time() - ($_SESSION['guest_last_report'] ?? 0) < 120) {
        $errors['form'] = 'You just sent a report. Wait 2 minutes before sending another, or check it with your tracking code.';
    } else {
        [$errors, $d] = validate_report($_POST, $_FILES, $types, true);
    }
    if (!$errors) {
        try {
            [$id, $code] = save_report($conn, $d, $_FILES, null, null, true);
            $_SESSION['guest_last_report'] = time();
            $_SESSION['guest_incidents'][] = $id;   // lets this browser see the report + photos
            redirect('track.php?code=' . urlencode($code) . '&new=1');
        } catch (Throwable $ex) {
            error_log('guest report save: ' . $ex->getMessage());
            $errors['form'] = 'Your report could not be saved. Check your connection and try again, or call the hotlines.';
        }
    }
}

auth_top('Report without an account', '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">', true);
?>
<h2>Report an emergency</h2>
<p class="lead-sm">No account needed. You will get a tracking code to follow your report.</p>
<?php if (isset($errors['form'])): ?><div class="alert alert-danger"><?= e($errors['form']) ?></div><?php endif; ?>
<?php render_report_form($types, $d, array_diff_key($errors, ['form' => 1]), true); ?>
<p class="text-center mt-4 mb-0 small">Already reported? <a href="<?= url('track.php') ?>" class="fw-semibold">Track it with your code</a> &middot; <a href="<?= url('login.php') ?>" class="fw-semibold">Log in</a></p>
<?php auth_bottom(report_form_scripts()); ?>

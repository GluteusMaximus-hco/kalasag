<?php
// resident sends an emergency report
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/incidents.php';
require_once __DIR__ . '/../includes/report_form.php';
$u = require_role('resident');

$page_title = 'Report an emergency';
$active = 'report';
$types = incident_types($conn);
$d = report_defaults();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$errors, $d] = validate_report($_POST, $_FILES, $types, false);
    if (!$errors) {
        try {
            [$id] = save_report($conn, $d, $_FILES, my_resident_id($conn, $u['user_id']), $u['user_id'], false);
            flash('success', 'Report sent. The barangay can see it now, and you will get updates here.');
            redirect('resident/report_view.php?id=' . $id);
        } catch (Throwable $ex) {
            error_log('report save: ' . $ex->getMessage());
            $errors['form'] = 'Your report could not be saved. Check your connection and try again, or call the hotlines.';
        }
    }
}

$extra_head = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">';
include __DIR__ . '/../includes/header.php';
?>
<div class="report-wrap">
    <?php if (isset($errors['form'])): ?><div class="alert alert-danger"><?= e($errors['form']) ?></div><?php endif; ?>
    <?php render_report_form($types, $d, array_diff_key($errors, ['form' => 1]), false); ?>
</div>
<?php
$extra_scripts = report_form_scripts();
include __DIR__ . '/../includes/footer.php';

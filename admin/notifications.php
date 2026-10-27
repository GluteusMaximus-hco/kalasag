<?php
// SMS + email: are they set up, send a check message, and the recent send log
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notify.php';
$u = require_role('admin');

$page_title = 'SMS & email';
$active = 'notify';

// the admin's own number/email is filled in by default
$st = $conn->prepare('SELECT contact_no, email FROM users WHERE user_id = ?');
$st->bind_param('i', $u['user_id']);
$st->execute();
$me = $st->get_result()->fetch_assoc();
$test_phone = $me['contact_no'] ?? '';
$test_email = $me['email'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    // these send right away (not after the page) so the admin sees if it worked
    if ($action === 'test_sms') {
        $test_phone = trim($_POST['phone'] ?? '');
        if (!ph_e164($test_phone)) {
            flash('danger', 'Use an 11-digit mobile number starting with 09.');
        } else {
            [$ok, $detail] = sms_send($test_phone, 'KALASAG Barangay 172: SMS alerts are on. You will get emergency updates and announcements on this number.', 'check');
            flash($ok ? 'success' : 'danger', $ok ? 'SMS check sent to the phone app (' . $detail . '). It should arrive in a few seconds.' : 'SMS failed: ' . $detail);
        }
    } elseif ($action === 'test_email') {
        $test_email = trim($_POST['email'] ?? '');
        if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'Enter a valid email address.');
        } else {
            [$ok, $detail] = email_send($test_email, $u['full_name'], 'KALASAG: Email alerts are on', 'Email alerts are on',
                ['You will get emergency report updates and announcements from Barangay 172 at this address.'], app_url(), 'check', 'Open KALASAG');
            flash($ok ? 'success' : 'danger', $ok ? 'Email check sent to ' . $test_email . '. Look in the inbox (and spam folder).' : 'Email failed: ' . $detail . '. Check config/mail.php.');
        }
    }
    redirect('admin/notifications.php');
}

$log = notify_log_read(60);
$sms_on = sms_ready();
$mail_on = mail_ready();

include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-6">
        <section class="panel h-100">
            <div class="panel-head"><h2>SMS</h2>
                <span class="badge <?= $sms_on ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $sms_on ? 'Set up' : 'Not set up' ?></span></div>
            <div class="panel-body">
                <?php if ($sms_on): ?>
                    <p class="small text-secondary">Texts go out through the SMS Gateway for Android app on the barangay's phone. The phone needs internet and load or unli text.</p>
                <?php else: ?>
                    <p class="small text-secondary">Texts are off. To turn them on, install the SMS Gateway for Android app on the phone, then copy <code>config/sms.example.php</code> to <code>config/sms.php</code> and put in the username and password from the app.</p>
                <?php endif; ?>
                <form method="post" class="d-flex gap-2 flex-wrap">
                    <?= csrf_field() ?><input type="hidden" name="action" value="test_sms">
                    <label class="visually-hidden" for="phone">Mobile number</label>
                    <input class="form-control flex-grow-1" style="max-width:220px" id="phone" name="phone" inputmode="numeric" placeholder="09XXXXXXXXX" value="<?= e($test_phone) ?>">
                    <button class="btn btn-umber" type="submit" <?= $sms_on ? '' : 'disabled' ?>><i class="bi bi-send me-1"></i>Send SMS check</button>
                </form>
            </div>
        </section>
    </div>
    <div class="col-lg-6">
        <section class="panel h-100">
            <div class="panel-head"><h2>Email</h2>
                <span class="badge <?= $mail_on ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $mail_on ? 'Set up' : 'Not set up' ?></span></div>
            <div class="panel-body">
                <?php if ($mail_on): ?>
                    <p class="small text-secondary">Emails go out from <strong><?= e(MAIL_USERNAME) ?></strong>. They're only sent to people who added an email to their account.</p>
                <?php else: ?>
                    <p class="small text-secondary">Emails are off. Copy <code>config/mail.example.php</code> to <code>config/mail.php</code> and put in the Gmail address and its app password.</p>
                <?php endif; ?>
                <form method="post" class="d-flex gap-2 flex-wrap">
                    <?= csrf_field() ?><input type="hidden" name="action" value="test_email">
                    <label class="visually-hidden" for="email">Email</label>
                    <input class="form-control flex-grow-1" style="max-width:260px" id="email" name="email" type="email" placeholder="you@gmail.com" value="<?= e($test_email) ?>">
                    <button class="btn btn-umber" type="submit" <?= $mail_on ? '' : 'disabled' ?>><i class="bi bi-envelope me-1"></i>Send email check</button>
                </form>
            </div>
        </section>
    </div>

    <div class="col-xl-5">
        <section class="panel h-100">
            <div class="panel-head"><h2>What gets sent</h2></div>
            <div class="panel-body">
                <ul class="small mb-0 ps-3">
                    <li class="mb-2"><strong>New report:</strong> the reporter gets a confirmation (guests also get their tracking code), and every admin gets an alert.</li>
                    <li class="mb-2"><strong>Acknowledged, resolved, closed:</strong> the reporter, with your remark.</li>
                    <li class="mb-2"><strong>Remark only:</strong> the reporter gets the update.</li>
                    <li class="mb-2"><strong>Team dispatched:</strong> the reporter, and the team's contact number gets the assignment.</li>
                    <li class="mb-2"><strong>Team arrived:</strong> the reporter.</li>
                    <li class="mb-2"><strong>Message from the barangay or the team:</strong> the reporter.</li>
                    <li><strong>Announcement:</strong> every active resident, only when "Also text and email all residents" is ticked.</li>
                </ul>
                <p class="small text-secondary mt-3 mb-0">Texts and emails go out after the page saves, so a slow phone or Gmail never stops a report from being handled.</p>
            </div>
        </section>
    </div>
    <div class="col-xl-7">
        <section class="panel h-100">
            <div class="panel-head"><h2>Recent sends</h2><span class="hint">Newest first, numbers partly hidden</span></div>
            <?php if (!$log): ?>
                <div class="empty"><i class="bi bi-bell" style="color:var(--muted)"></i>Nothing sent yet.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>When</th><th>Type</th><th>For</th><th>To</th><th>Result</th></tr></thead>
                        <tbody>
                            <?php foreach ($log as $l): ?>
                                <tr>
                                    <td class="text-nowrap small"><?= e(time_ago($l['at'] ?? '')) ?></td>
                                    <td class="text-nowrap"><i class="bi <?= ($l['ch'] ?? '') === 'sms' ? 'bi-chat-dots' : (($l['ch'] ?? '') === 'email' ? 'bi-envelope' : 'bi-gear') ?> me-1"></i><?= e(strtoupper($l['ch'] ?? '')) ?></td>
                                    <td class="small"><?= e($l['ev'] ?? '') ?></td>
                                    <td class="small text-nowrap"><?= e($l['to'] ?? '') ?></td>
                                    <td class="small"><span class="badge <?= !empty($l['ok']) ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= !empty($l['ok']) ? 'Sent' : 'Not sent' ?></span>
                                        <div class="text-secondary"><?= e($l['detail'] ?? '') ?></div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>

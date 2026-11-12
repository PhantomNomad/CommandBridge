<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_csrf_for_post();

require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/mail.php';
require_login();
require_user_management_access();

$pdo = db();
$userId = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, username, real_name, email FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    render_header('User Not Found');
    echo '<div class="card"><p>User not found.</p></div>';
    render_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (($user['email'] ?? '') === '') {
        flash('error', 'This user does not have an email address.');
    } elseif ($subject === '' || $message === '') {
        flash('error', 'Subject and message are required.');
    } elseif (app_send_mail((string)$user['email'], $subject, $message)) {
        audit_log((int)(current_user()['id'] ?? 0), 'email_user', 'user', $userId, 'Sent email to user from admin/email_user.php');
        flash('success', 'Email sent.');
        header('Location: ' . app_url('/admin/user_edit.php?id=' . $userId));
        exit;
    } else {
        flash('error', 'Email was not sent. Check the email address and server mail configuration.');
    }
}

render_header('Email User');
?>
<div class="card">
  <h2>Email User</h2>
  <p>To: <?= e($user['real_name'] . ' <' . ($user['email'] ?? '') . '>') ?></p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="user_id" value="<?= e((string)$userId) ?>">
    <div class="grid">
      <div><label>Subject</label><input name="subject" value="Command Bridge reminder" required></div>
      <div style="grid-column:1/-1;"><label>Message</label><textarea name="message" rows="8" required>Please complete your ICS 214 before leaving.</textarea></div>
    </div>
    <div style="margin-top:16px;">
      <button type="submit">Send Email</button>
      <a class="btn btn-secondary" href="<?= e(app_url('/admin/user_edit.php?id=' . $userId)) ?>">Cancel</a>
    </div>
  </form>
</div>
<?php render_footer(); ?>

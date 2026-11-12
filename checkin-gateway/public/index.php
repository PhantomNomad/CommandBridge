<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$token = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['location'] ?? ''));
if (strlen($token) !== 32) gateway_render_error(404, 'A valid check-in location link is required.');
$location = gateway_location_by_token($token);
if (!$location || $location['lifecycle_status'] !== 'active') gateway_render_error(404, 'This check-in location is not currently active.');
gateway_render_header('Location Access');
gateway_show_flash();
?>
<p class="muted">Enter your individual CIR/SAM PIN. Your access is limited to this check-in location.</p>
<section class="card"><h2><?= gateway_h($location['location_name']) ?></h2><p><?= gateway_h($location['location_type']) ?><br><?= gateway_h($location['street_address']) ?></p>
<form method="post" action="/start_session.php" autocomplete="off">
<input type="hidden" name="csrf" value="<?= gateway_h(gateway_csrf_token()) ?>"><input type="hidden" name="location_token" value="<?= gateway_h($location['location_token']) ?>">
<label>Individual CIR/SAM PIN <input name="companion_pin" type="password" inputmode="numeric" pattern="[0-9]+" required maxlength="64" autocomplete="one-time-code"></label>
<button type="submit">Access check-in</button></form></section>
<?php gateway_render_footer(); ?>
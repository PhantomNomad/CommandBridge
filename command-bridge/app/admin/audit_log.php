<?php
declare(strict_types=1);
require_once __DIR__ . '/settings_helpers.php';
$stmt=db()->query("SELECT a.*, COALESCE(u.real_name,u.username,'System') AS user_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 250");
$rows=$stmt->fetchAll();
render_header('Audit Log'); settings_menu('audit'); ?>
<div class="card"><h2>Audit Log</h2><p>Recent administrator, permission, configuration, and operational record actions.</p>
<table><thead><tr><th>Date/time</th><th>User</th><th>Action</th><th>Record</th><th>Details</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?=e($r['created_at'])?></td><td><?=e($r['user_name'])?></td><td><?=e($r['action_type'])?></td><td><?=e($r['entity_type'])?> #<?= (int)$r['entity_id'] ?></td><td><?=e($r['action_detail'])?></td></tr><?php endforeach ?></tbody></table></div>
<?php render_footer(); ?>
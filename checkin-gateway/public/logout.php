<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')gateway_render_error(405,'Method not allowed.');gateway_require_csrf();gateway_start_session();$locationId=(int)($_SESSION['location_id']??0);if($locationId)gateway_audit('location_session_ended',$locationId);$_SESSION=[];session_destroy();gateway_render_header('Session ended');echo '<div class="notice success">Your check-in session has ended.</div>';gateway_render_footer();

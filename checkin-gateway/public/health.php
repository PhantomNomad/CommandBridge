<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
try { gateway_db()->query('SELECT 1'); gateway_json(200,['ok'=>true]); }
catch(Throwable $e) { gateway_log('health_failure',['error'=>$e->getMessage()]); gateway_json(503,['ok'=>false]); }

<?php
require_once __DIR__ . '/includes/auth.php';
$form = isset($_GET['form']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string)$_GET['form']) : 'This form';
$feature = isset($_GET['feature']) ? htmlspecialchars((string)$_GET['feature'], ENT_QUOTES, 'UTF-8') : 'This feature';
?><!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Not Implemented</title>
<style>body{font-family:system-ui,Arial,sans-serif;background:#f4f6f8;margin:0}.box{max-width:560px;margin:60px auto;padding:20px 24px;background:#fff8e1;border:1px solid #f0c36d;border-radius:6px}h1{font-size:1.25rem;margin:0 0 10px}</style>
</head><body><div class="box"><h1>Not Implemented</h1><p><?= $feature ?> for <?= htmlspecialchars($form, ENT_QUOTES, 'UTF-8') ?> is not implemented yet.</p><p>Please use the browser back button or close this tab.</p></div></body></html>

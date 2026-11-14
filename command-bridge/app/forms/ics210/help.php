<?php
declare(strict_types=1);
// The "Help / Instructions" link on the form. The instructions are kept in the help system
// (app/help/topics/forms/ics210/index.md), so this page just sends the user there.
require_once dirname(dirname(__DIR__)) . '/includes/layout.php';
require_once dirname(dirname(__DIR__)) . '/includes/help.php';
require_login();
header('Location: ' . cb_help_url('forms/ics210/index'));
exit;

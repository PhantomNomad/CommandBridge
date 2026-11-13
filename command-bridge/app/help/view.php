<?php
declare(strict_types=1);

/*
 * Shows one help topic: ?topic=admin/smtp            full page (opened in a new tab from the panel)
 *                       ?topic=admin/smtp&panel=1    just the text, loaded into the side panel by assets/js/help_panel.js
 * ?topic=index lists every help topic. Sign-in is required except for CB_HELP_PUBLIC_TOPICS (the sign-in page's help).
 */

require_once dirname(__DIR__) . '/includes/layout.php';
require_once dirname(__DIR__) . '/includes/help.php';

$topic = strtolower(trim((string)($_GET['topic'] ?? 'index')));
if (!cb_help_topic_is_valid($topic)) {
    $topic = 'index';
}
if (!in_array($topic, CB_HELP_PUBLIC_TOPICS, true)) {
    require_login();
}
$panel = isset($_GET['panel']);
$help = cb_help_load($topic);

if ($topic === 'index') {
    $groups = [
        'admin' => 'Settings and administration',
        'forms' => 'Forms and units',
        'operational_periods' => 'Operational periods',
        'dashboard' => 'Dashboard',
        'setup' => 'First-run setup',
        '' => 'Signing in and your account',
    ];
    $byGroup = [];
    foreach (cb_help_all_topics() as $t) {
        $first = str_contains($t['topic'], '/') ? explode('/', $t['topic'])[0] : '';
        $byGroup[isset($groups[$first]) ? $first : ''][] = $t;
    }
    $list = '';
    foreach ($groups as $key => $label) {
        if (empty($byGroup[$key])) {
            continue;
        }
        $list .= '<h2 id="' . cb_help_e(cb_help_slug($label)) . '">' . cb_help_e($label) . '</h2><ul class="help-topic-list">';
        foreach ($byGroup[$key] as $t) {
            $list .= '<li><a href="' . cb_help_e(cb_help_url($t['topic'])) . '" data-help-topic="' . cb_help_e($t['topic']) . '">'
                . cb_help_e($t['title']) . '</a></li>';
        }
        $list .= '</ul>';
    }
    $help['html'] .= $list;
    $help['found'] = true;
}

if ($panel) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<div class="help-doc" data-help-title="' . cb_help_e($help['title']) . '">' . $help['html'] . '</div>';
    exit;
}

render_header('Help: ' . $help['title'], $topic);
?>
<div class="card help-doc help-page">
  <?= $help['html'] ?>
  <p class="help-page-links no-print">
    <?php if ($topic !== 'index'): ?><a href="<?= e(cb_help_url('index')) ?>">All help topics</a><?php endif; ?>
  </p>
</div>
<?php render_footer(); ?>

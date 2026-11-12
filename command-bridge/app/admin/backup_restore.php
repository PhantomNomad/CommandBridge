<?php
declare(strict_types=1);

require_once __DIR__ . '/settings_helpers.php';
require_once dirname(__DIR__) . '/includes/backup_restore.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'download_backup') {
        try {
            audit_log((int) (current_user()['id'] ?? 0), 'system_backup_downloaded', 'system', 0, 'Application data backup downloaded.');
            command_bridge_backup_stream_download();
        } catch (Throwable $exception) {
            security_log($exception, 'Backup download failed');
            $message = $exception instanceof CommandBridgeBackupException
                ? $exception->getMessage()
                : 'The backup could not be created. Review the server error log.';
            flash('error', $message);
            header('Location: ' . app_url('/admin/backup_restore.php'));
            exit;
        }
    }

    if ($action === 'restore_backup') {
        if ((string) ($_POST['restore_confirmation'] ?? '') !== 'RESTORE') {
            flash('error', 'Type RESTORE exactly to confirm the restore operation.');
            header('Location: ' . app_url('/admin/backup_restore.php'));
            exit;
        }

        try {
            $result = command_bridge_backup_restore($_FILES['backup_file'] ?? []);
            audit_log(
                (int) (current_user()['id'] ?? 0),
                'system_backup_restored',
                'system',
                0,
                'Application data backup restored. Tables: ' . $result['tables'] . '; files: ' . $result['files'] . '.'
            );
            flash('success', 'Backup restored successfully. ' . $result['tables'] . ' database tables and ' . $result['files'] . ' application files were processed.');
        } catch (Throwable $exception) {
            security_log($exception, 'Backup restore failed');
            $message = $exception instanceof CommandBridgeBackupException
                ? $exception->getMessage()
                : 'The backup could not be restored. Review the server error log.';
            flash('error', $message);
        }

        header('Location: ' . app_url('/admin/backup_restore.php'));
        exit;
    }

    flash('error', 'Unsupported backup action.');
    header('Location: ' . app_url('/admin/backup_restore.php'));
    exit;
}

render_header('Backup & Restore');
settings_menu('backup');
?>

<section class="card">
    <h2>Application data backup</h2>
    <p>Create a downloadable backup of the Command Bridge database, uploaded operational files, and branding files.</p>
    <p class="muted">Backups do not include Apache configuration, database credentials, TLS certificates, or application source code. Protect downloaded backups as sensitive operational records.</p>

    <?php if (!class_exists('ZipArchive')): ?>
        <div class="alert alert-error">The PHP ZipArchive extension is required before backup and restore can be used.</div>
    <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="download_backup">
            <button class="btn btn-primary" type="submit">Download backup ZIP</button>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Restore application data</h2>
    <div class="alert alert-warning">
        Restoring replaces current database records, uploads, and branding files with the selected backup. This action cannot be undone from the web application.
    </div>
    <p>Only restore ZIP files created by this Command Bridge backup page and compatible with this installation.</p>

    <?php if (class_exists('ZipArchive')): ?>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="restore_backup">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= COMMAND_BRIDGE_BACKUP_MAX_BYTES ?>">

            <div class="form-row">
                <label for="backup_file">Backup ZIP file</label>
                <input id="backup_file" name="backup_file" type="file" accept=".zip,application/zip" required>
            </div>

            <div class="form-row">
                <label for="restore_confirmation">Confirmation</label>
                <input id="restore_confirmation" name="restore_confirmation" type="text" autocomplete="off" placeholder="Type RESTORE" required>
            </div>

            <button class="btn btn-danger" type="submit">Restore backup</button>
        </form>
    <?php endif; ?>
</section>

<?php render_footer(); ?>

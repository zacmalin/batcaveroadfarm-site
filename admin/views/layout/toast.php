<?php // admin/views/layout/toast.php — message after a save. One look on every admin page (Nov 14, 2026; style in admin/assets/admin-ui.css):
// success fades after 5 seconds; a warning or error stays until ✕ is tapped. Needs $toastType ('success'|'ok'|'warning'|'error'|'err') and $toastMessage.
if (!empty($toastMessage)):
    $toastKind = in_array($toastType ?? '', ['error', 'err'], true) ? 'err' : (($toastType ?? '') === 'warning' ? 'warn' : 'ok');
?>
    <div id="toastNotification" class="ui-toast is-on<?= $toastKind === 'ok' ? ' is-auto' : ' is-' . $toastKind ?>" role="<?= $toastKind === 'ok' ? 'status' : 'alert' ?>">
        <span><?= htmlspecialchars((string)$toastMessage, ENT_QUOTES, 'UTF-8') ?></span>
        <?php if ($toastKind !== 'ok'): ?><button type="button" class="ui-toast-x" data-toast-close aria-label="Close">✕</button><?php endif; ?>
    </div>
<?php endif; ?>

<?php
/** @var \App\View\AppView $this */
$notifications = $notifications ?? [];
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="mb-1">通知一覧</h2>
        <div class="text-muted small">差し戻し通知などのアプリ内通知を表示します。</div>
    </div>
    <button type="button" id="mark-all-read-btn" class="btn btn-outline-primary btn-sm">すべて既読にする</button>
</div>

<?php if (empty($notifications)): ?>
    <div class="alert alert-light border">通知はありません。</div>
<?php else: ?>
    <div class="list-group shadow-sm">
        <?php foreach ($notifications as $notification): ?>
            <?php
            $isRead = (int)$notification->i_is_read === 1;
            $link = $notification->c_link ?: '#';
            ?>
            <div class="list-group-item <?= $isRead ? '' : 'list-group-item-info' ?>" data-notification-id="<?= (int)$notification->i_id_notification ?>">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <strong><?= h($notification->c_title) ?></strong>
                            <?php if (!$isRead): ?>
                                <span class="badge bg-danger">未読</span>
                            <?php endif; ?>
                        </div>
                        <div class="mb-2"><?= h($notification->c_message) ?></div>
                        <div class="small text-muted"><?= h($notification->dt_create?->format('Y-m-d H:i')) ?></div>
                    </div>
                    <div class="d-flex flex-column gap-2">
                        <?php if ($link !== '#'): ?>
                            <a class="btn btn-sm btn-primary open-notification-link" href="<?= h($this->Url->build($link)) ?>" data-id="<?= (int)$notification->i_id_notification ?>">開く</a>
                        <?php endif; ?>
                        <?php if (!$isRead): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary mark-read-btn" data-id="<?= (int)$notification->i_id_notification ?>">既読</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('meta[name="csrfToken"]')?.getAttribute('content') ?? '';

    async function postJson(url, body) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify(body),
        });

        return response.json();
    }

    document.querySelectorAll('.mark-read-btn').forEach((button) => {
        button.addEventListener('click', async () => {
            const id = Number(button.dataset.id);
            const result = await postJson('<?= $this->Url->build('/Notifications/markRead') ?>', { ids: [id] });
            if (result.success) {
                location.reload();
            }
        });
    });

    document.querySelectorAll('.open-notification-link').forEach((link) => {
        link.addEventListener('click', async (event) => {
            const id = Number(link.dataset.id);
            await postJson('<?= $this->Url->build('/Notifications/markRead') ?>', { ids: [id] });
        });
    });

    document.getElementById('mark-all-read-btn')?.addEventListener('click', async () => {
        const result = await postJson('<?= $this->Url->build('/Notifications/markAllRead') ?>', {});
        if (result.success) {
            location.reload();
        }
    });
});
</script>

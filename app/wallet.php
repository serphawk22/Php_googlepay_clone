<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
$userId = (int) $user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    $result = wallet_topup($userId, field('amount'), field('serp_pin'));
    flash(!empty($result['ok']) ? 'success' : 'error', (string) $result['message']);
    redirect('app/wallet.php');
}

$fresh = current_user(true) ?? $user;
$entries = db()->prepare('SELECT * FROM wallet_entries WHERE user_id = ? ORDER BY id DESC LIMIT 30');
$entries->execute([$userId]);
$rows = $entries->fetchAll();

render_shell_start('Wallet', 'wallet', 'user', 'This balance pays LIC and electricity bills inside SERP UPI.');
?>
<div class="split">
    <section class="panel stack">
        <p class="muted">Available balance</p>
        <p style="font-family:var(--display);font-size:42px;line-height:1"><?= e(money($fresh['wallet_balance'] ?? 0)) ?></p>
        <p class="notice">Adding money records it in your SERP wallet. It does not debit a bank account, and it does not ask for a bank PIN.</p>
        <?php if (!user_has_pin($fresh)): ?>
            <p>Create your SERP UPI PIN before you add money.</p>
            <a class="btn btn-primary" href="<?= e(url('app/profile.php')) ?>#pin">Create PIN</a>
        <?php else: ?>
            <form method="post" class="stack" autocomplete="off">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="amount">Amount to add</label>
                    <input id="amount" name="amount" inputmode="decimal" placeholder="500" required>
                    <p class="help">From 1.00 to 1,00,000.00 in one step.</p>
                </div>
                <?php render_pin_field('serp_pin', 'SERP UPI PIN', 'serp_pin'); ?>
                <button class="btn btn-primary" type="submit">Add money</button>
            </form>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2 style="font-size:24px">Wallet activity</h2>
        <?php if (!$rows): ?>
            <p class="muted" style="margin-top:8px">No wallet activity yet.</p>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:10px">
                <table class="data">
                    <thead><tr><th>When</th><th>What</th><th>Amount</th><th>Balance</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e(format_when((string) $row['created_at'])) ?></td>
                            <td><?= e(ucfirst((string) $row['kind'])) ?><?php if ($row['note'] !== ''): ?><br><span class="muted"><?= e($row['note']) ?></span><?php endif; ?></td>
                            <td><?= e(money($row['amount'])) ?></td>
                            <td><?= e(money($row['balance_after'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php render_shell_end('user', 'wallet'); ?>

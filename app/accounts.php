<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
$userId = (int) $user['id'];
$errors = [];
$defaultDays = (int) setting('default_remind_days', '3');
if (!in_array($defaultDays, remind_day_options(), true)) {
    $defaultDays = 3;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    $action = field('action');
    if ($action === 'link') {
        $billerId = (int) field('biller_id');
        $number = clean_text(field('account_number'), 16);
        $holder = clean_text(field('holder_name'), 120);
        $nickname = clean_text(field('nickname'), 40);
        $remindDays = (int) field('remind_days');
        $billerStmt = db()->prepare("SELECT * FROM billers WHERE id = ? AND status = 'active' LIMIT 1");
        $billerStmt->execute([$billerId]);
        $biller = $billerStmt->fetch();
        if (!$biller) {
            $errors[] = 'Choose a biller.';
        }
        if (!valid_account_number($number)) {
            $errors[] = 'Enter the policy or consumer number, 8 to 16 digits.';
        }
        if (!valid_name($holder)) {
            $errors[] = 'Enter the name on the policy or bill.';
        }
        if (!in_array($remindDays, remind_day_options(), true)) {
            $errors[] = 'Choose when the reminder should start.';
        }
        if (!$errors && $biller) {
            $find = db()->prepare('SELECT id, status FROM linked_accounts WHERE user_id = ? AND biller_id = ? AND account_number = ? LIMIT 1');
            $find->execute([$userId, $billerId, $number]);
            $existing = $find->fetch();
            if ($existing && $existing['status'] === 'active') {
                $errors[] = 'That account is already linked.';
            } else {
                if ($existing) {
                    db()->prepare(
                        "UPDATE linked_accounts
                         SET holder_name = ?, nickname = ?, remind_days = ?, status = 'active', updated_at = NOW()
                         WHERE id = ?"
                    )->execute([$holder, $nickname, $remindDays, (int) $existing['id']]);
                    $accountId = (int) $existing['id'];
                } else {
                    db()->prepare(
                        'INSERT INTO linked_accounts (user_id, biller_id, account_number, holder_name, nickname, remind_days)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    )->execute([$userId, $billerId, $number, $holder, $nickname, $remindDays]);
                    $accountId = (int) db()->lastInsertId();
                }
                audit('account.link', 'linked_account', $accountId, $number);
                $imported = import_latest_bill($userId, $accountId);
                flash('success', 'Account linked.');
                flash(!empty($imported['ok']) ? 'success' : 'info', (string) $imported['message']);
                if (!empty($imported['bill_id'])) {
                    redirect('app/bills.php?id=' . (int) $imported['bill_id']);
                }
                redirect('app/accounts.php');
            }
        }
    } elseif ($action === 'fetch') {
        $result = import_latest_bill($userId, (int) field('account_id'));
        flash(!empty($result['ok']) ? 'success' : 'error', (string) $result['message']);
        if (!empty($result['bill_id'])) {
            redirect('app/bills.php?id=' . (int) $result['bill_id']);
        }
        redirect('app/accounts.php');
    } elseif ($action === 'remove') {
        $stmt = db()->prepare("UPDATE linked_accounts SET status = 'removed', updated_at = NOW() WHERE id = ? AND user_id = ? AND status = 'active'");
        $stmt->execute([(int) field('account_id'), $userId]);
        if ($stmt->rowCount() === 1) {
            audit('account.remove', 'linked_account', (int) field('account_id'), '');
            flash('success', 'Account removed. Older bills stay in your history.');
        } else {
            flash('error', 'That account could not be removed.');
        }
        redirect('app/accounts.php');
    }
}

$accounts = db()->prepare(
    "SELECT la.*, bi.name AS biller_name, bi.category, bi.region,
        (SELECT b.id FROM bills b WHERE b.linked_account_id = la.id ORDER BY b.due_date DESC, b.id DESC LIMIT 1) AS latest_bill_id,
        (SELECT b.status FROM bills b WHERE b.linked_account_id = la.id ORDER BY b.due_date DESC, b.id DESC LIMIT 1) AS latest_status
     FROM linked_accounts la
     INNER JOIN billers bi ON bi.id = la.biller_id
     WHERE la.user_id = ? AND la.status = 'active'
     ORDER BY la.id DESC"
);
$accounts->execute([$userId]);
$linked = $accounts->fetchAll();
$billers = active_billers();
$samples = sample_accounts();

render_shell_start('Linked accounts', 'accounts', 'user', 'LIC policies and Bihar electricity consumer numbers.');
render_errors($errors);
?>
<div class="split">
    <form method="post" class="panel stack" id="link">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="link">
        <h2 style="font-size:26px">Link a biller</h2>
        <div class="field">
            <label for="biller_id">Biller</label>
            <select id="biller_id" name="biller_id" required>
                <option value="">Choose</option>
                <?php
                $group = '';
                foreach ($billers as $biller):
                    $label = category_label((string) $biller['category']);
                    if ($label !== $group):
                        if ($group !== '') {
                            echo '</optgroup>';
                        }
                        $group = $label;
                        echo '<optgroup label="' . e($label) . '">';
                    endif;
                    $selected = ((string) ($_POST['biller_id'] ?? '')) === (string) $biller['id'] ? ' selected' : '';
                    echo '<option value="' . (int) $biller['id'] . '"' . $selected . '>' . e($biller['name']) . '</option>';
                endforeach;
                if ($group !== '') {
                    echo '</optgroup>';
                }
                ?>
            </select>
        </div>
        <div class="field">
            <label for="account_number">Policy or consumer number</label>
            <input id="account_number" name="account_number" inputmode="numeric" value="<?= old('account_number') ?>" required>
        </div>
        <div class="field">
            <label for="holder_name">Name on the bill</label>
            <input id="holder_name" name="holder_name" value="<?= old('holder_name') ?>" required>
        </div>
        <div class="form-row">
            <div class="field">
                <label for="nickname">Nickname</label>
                <input id="nickname" name="nickname" value="<?= old('nickname') ?>" placeholder="Home, shop, parents">
            </div>
            <div class="field">
                <label for="remind_days">Remind me</label>
                <select id="remind_days" name="remind_days">
                    <?php foreach (remind_day_options() as $days): ?>
                        <option value="<?= $days ?>"<?= selected_attr((string) ($_POST['remind_days'] ?? $defaultDays), (string) $days) ?>><?= $days === 1 ? '1 day before' : $days . ' days before' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button class="btn btn-primary" type="submit">Link and fetch</button>
    </form>
    <aside class="stack">
        <?php if ($samples): ?>
            <div class="hint">
                <h3>Trial numbers</h3>
                <p>These sample bills are already loaded. Link one to see a fetch, a reminder, and a payment. Real customer numbers are not listed here.</p>
                <ul>
                    <?php foreach ($samples as $sample): ?>
                        <li><strong><?= e($sample['account_number']) ?></strong> · <?= e($sample['biller_name']) ?> · <?= e($sample['customer_name']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <div class="panel">
            <h2 style="font-size:22px">What gets stored</h2>
            <p class="muted" style="margin-top:8px">The number, the name, and the reminder. When a bill exists for that number, it is fetched immediately. You pay it here with the 6-digit SERP UPI PIN from your profile.</p>
        </div>
    </aside>
</div>

<section style="margin-top:18px">
    <h2 style="font-size:26px">Your connections</h2>
    <?php if (!$linked): ?>
        <div class="empty" style="margin-top:12px"><p>No accounts linked yet.</p></div>
    <?php else: ?>
        <div class="card-grid" style="margin-top:12px">
            <?php foreach ($linked as $account): ?>
                <article class="card">
                    <span class="badge"><?= e(category_label((string) $account['category'])) ?></span>
                    <h3 style="margin-top:8px"><?= e($account['nickname'] !== '' ? $account['nickname'] : $account['biller_name']) ?></h3>
                    <p><?= e($account['biller_name']) ?></p>
                    <p class="muted"><?= e($account['account_number']) ?> · <?= e($account['holder_name']) ?></p>
                    <p class="muted">Reminder <?= (int) $account['remind_days'] ?> day<?= (int) $account['remind_days'] === 1 ? '' : 's' ?> before the due date.</p>
                    <?php if ($account['latest_status']): ?>
                        <p style="margin-top:8px"><span class="badge badge-<?= e((string) $account['latest_status']) ?>"><?= e(status_label((string) $account['latest_status'])) ?></span></p>
                    <?php endif; ?>
                    <div class="cluster" style="margin-top:12px">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="fetch">
                            <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                            <button class="btn btn-sm btn-primary" type="submit">Fetch bill</button>
                        </form>
                        <?php if ($account['latest_bill_id']): ?>
                            <a class="btn btn-sm btn-ghost" href="<?= e(url('app/bills.php?id=' . (int) $account['latest_bill_id'])) ?>">Latest bill</a>
                        <?php endif; ?>
                        <form method="post" data-confirm="Remove this linked account?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                            <button class="btn btn-sm btn-ghost" type="submit">Remove</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php render_shell_end('user', 'accounts'); ?>

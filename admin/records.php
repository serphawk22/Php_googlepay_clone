<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

require_admin();
$errors = [];
$billers = db()->query('SELECT * FROM billers ORDER BY category, name')->fetchAll();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    $action = field('action');
    $recordId = (int) field('id');
    if ($action === 'deactivate' || $action === 'activate') {
        $active = $action === 'activate' ? 1 : 0;
        db()->prepare('UPDATE biller_records SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([$active, $recordId]);
        audit('record.' . $action, 'biller_record', $recordId, '');
        flash('success', $active ? 'Bill record is available to fetch again.' : 'Bill record hidden from fetch. Customer bills already fetched are unchanged.');
        redirect('admin/records.php?id=' . $recordId);
    }
    if ($action === 'push') {
        $count = push_record_to_linked_users($recordId);
        audit('record.push', 'biller_record', $recordId, (string) $count);
        flash('success', 'Pushed to ' . $count . ' linked customer(s). Customers who already have this period are left as they are.');
        redirect('admin/records.php?id=' . $recordId);
    }
    if ($action === 'save') {
        $billerId = (int) field('biller_id');
        $number = clean_text(field('account_number'), 16);
        $customer = clean_text(field('customer_name'), 120);
        $period = clean_text(field('billing_period'), 40);
        $billNumber = clean_text(field('bill_number'), 40);
        $billDate = parse_date(field('bill_date'));
        $dueDate = parse_date(field('due_date'));
        $amount = parse_money(field('amount'));
        $lateRaw = field('late_fee');
        $late = $lateRaw === '' ? '0.00' : parse_money($lateRaw);
        $unitsRaw = field('units');
        $units = null;
        $plan = clean_text(field('plan_name'), 120);
        $details = clean_text(field('details'), 255);
        $sample = field('is_sample') === '1' ? 1 : 0;
        $active = field('is_active') === '0' ? 0 : 1;
        $knownBiller = false;
        foreach ($billers as $biller) {
            if ((int) $biller['id'] === $billerId) {
                $knownBiller = true;
            }
        }
        if (!$knownBiller) {
            $errors[] = 'Choose a biller.';
        }
        if (!valid_account_number($number)) {
            $errors[] = 'Enter an 8 to 16 digit account number.';
        }
        if (!valid_name($customer)) {
            $errors[] = 'Enter the customer name from the bill.';
        }
        if ($period === '' || $billNumber === '') {
            $errors[] = 'Enter the billing period and bill number.';
        }
        if ($billDate === null || $dueDate === null) {
            $errors[] = 'Enter the bill date and due date.';
        } elseif ($dueDate < $billDate) {
            $errors[] = 'The due date cannot be before the bill date.';
        }
        if ($amount === null || (float) $amount <= 0) {
            $errors[] = 'Enter an amount greater than zero.';
        }
        if ($late === null) {
            $errors[] = 'Enter a late fee of zero or more.';
        }
        if ($unitsRaw !== '') {
            if (!preg_match('/^\d{1,6}$/', $unitsRaw)) {
                $errors[] = 'Units must be a whole number.';
            } else {
                $units = (int) $unitsRaw;
            }
        }
        if (!$errors) {
            try {
                if ($recordId > 0) {
                    db()->prepare(
                        'UPDATE biller_records
                         SET biller_id = ?, account_number = ?, customer_name = ?, billing_period = ?, bill_number = ?,
                             bill_date = ?, due_date = ?, amount = ?, late_fee = ?, units = ?, plan_name = ?, details = ?,
                             is_sample = ?, is_active = ?, updated_at = NOW()
                         WHERE id = ?'
                    )->execute([$billerId, $number, $customer, $period, $billNumber, $billDate, $dueDate, $amount, $late, $units, $plan, $details, $sample, $active, $recordId]);
                } else {
                    db()->prepare(
                        'INSERT INTO biller_records (
                            biller_id, account_number, customer_name, billing_period, bill_number,
                            bill_date, due_date, amount, late_fee, units, plan_name, details, is_sample, is_active
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$billerId, $number, $customer, $period, $billNumber, $billDate, $dueDate, $amount, $late, $units, $plan, $details, $sample, $active]);
                    $recordId = (int) db()->lastInsertId();
                }
                audit('record.save', 'biller_record', $recordId, $number . ' ' . $period);
                if (field('push') === '1' && $active === 1) {
                    $count = push_record_to_linked_users($recordId);
                    flash('info', 'Created the bill for ' . $count . ' linked customer(s).');
                }
                flash('success', 'Bill record saved. Customers fetch it when they link this number, or you can push it.');
                redirect('admin/records.php?id=' . $recordId);
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    $errors[] = 'A record for this biller, number, and period already exists.';
                } else {
                    throw $e;
                }
            }
        }
    }
}

$formId = (int) ((($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') ? field('id') : ($_GET['id'] ?? 0));
$record = null;
if ($formId > 0) {
    $stmt = db()->prepare('SELECT * FROM biller_records WHERE id = ? LIMIT 1');
    $stmt->execute([$formId]);
    $record = $stmt->fetch() ?: null;
}
$value = static function (string $key, string $default = '') use ($record): string {
    $fallback = is_array($record) ? ($record[$key] ?? $default) : $default;
    if ($fallback === null) {
        $fallback = '';
    }
    return old($key, (string) $fallback);
};

$q = search_term();
$where = ' WHERE 1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (br.account_number LIKE ? OR br.customer_name LIKE ? OR br.bill_number LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$page = paged(
    'SELECT COUNT(*) FROM biller_records br INNER JOIN billers bi ON bi.id = br.biller_id' . $where,
    'SELECT br.*, bi.name AS biller_name FROM biller_records br INNER JOIN billers bi ON bi.id = br.biller_id' . $where . ' ORDER BY br.due_date DESC, br.id DESC',
    $params
);

render_shell_start('Bill records', 'records', 'admin', 'This is the book SERP UPI reads when a customer fetches a bill.');
render_errors($errors);
?>
<div class="split">
    <form method="post" class="panel stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $record ? (int) $record['id'] : 0 ?>">
        <h2 style="font-size:24px"><?= $record ? 'Edit bill record' : 'Add bill record' ?></h2>
        <div class="field"><label for="biller_id">Biller</label>
            <select id="biller_id" name="biller_id" required>
                <?php foreach ($billers as $biller): ?>
                    <option value="<?= (int) $biller['id'] ?>"<?= selected_attr($value('biller_id'), (string) $biller['id']) ?>><?= e($biller['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <div class="field"><label for="account_number">Account number</label><input id="account_number" name="account_number" value="<?= $value('account_number') ?>" required></div>
            <div class="field"><label for="customer_name">Customer name</label><input id="customer_name" name="customer_name" value="<?= $value('customer_name') ?>" required></div>
        </div>
        <div class="form-row">
            <div class="field"><label for="billing_period">Period</label><input id="billing_period" name="billing_period" value="<?= $value('billing_period', date('M Y')) ?>" placeholder="Oct 2026" required></div>
            <div class="field"><label for="bill_number">Bill number</label><input id="bill_number" name="bill_number" value="<?= $value('bill_number') ?>" required></div>
        </div>
        <div class="form-row">
            <div class="field"><label for="bill_date">Bill date</label><input id="bill_date" name="bill_date" type="date" value="<?= $value('bill_date', date('Y-m-01')) ?>" required></div>
            <div class="field"><label for="due_date">Due date</label><input id="due_date" name="due_date" type="date" value="<?= $value('due_date') ?>" required></div>
        </div>
        <div class="form-row">
            <div class="field"><label for="amount">Amount</label><input id="amount" name="amount" inputmode="decimal" value="<?= $value('amount') ?>" required></div>
            <div class="field"><label for="late_fee">Late fee</label><input id="late_fee" name="late_fee" inputmode="decimal" value="<?= $value('late_fee', '0.00') ?>"></div>
        </div>
        <div class="form-row">
            <div class="field"><label for="units">Units (kWh)</label><input id="units" name="units" inputmode="numeric" value="<?= $value('units') ?>"></div>
            <div class="field"><label for="plan_name">Plan</label><input id="plan_name" name="plan_name" value="<?= $value('plan_name') ?>" placeholder="Jeevan Anand or Domestic"></div>
        </div>
        <div class="field"><label for="details">Details</label><input id="details" name="details" value="<?= $value('details') ?>"></div>
        <label class="choice"><input type="checkbox" name="is_sample" value="1"<?= (($record && (int) $record['is_sample'] === 1 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') || field('is_sample') === '1') ? ' checked' : '' ?>> Show this number in the trial list</label>
        <?php
        $hideRecord = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')
            ? field('is_active') === '0'
            : (is_array($record) && (string) $record['is_active'] === '0');
        ?>
        <label class="choice"><input type="checkbox" name="is_active" value="0"<?= $hideRecord ? ' checked' : '' ?>> Hide from fetch</label>
        <label class="choice"><input type="checkbox" name="push" value="1" checked> Also create it for customers who already linked this number</label>
        <div class="cluster">
            <button class="btn btn-primary" type="submit">Save record</button>
            <?php if ($record): ?><a class="btn btn-ghost" href="<?= e(url('admin/records.php')) ?>">New record</a><?php endif; ?>
        </div>
        <p class="help">Saving does not rewrite a bill a customer already fetched. Edit that copy under Customer bills. Push only fills a period they do not have yet.</p>
    </form>
    <?php if ($record): ?>
        <div class="panel stack">
            <h2 style="font-size:24px">This record</h2>
            <p><?= e($record['customer_name']) ?> · <?= e($record['account_number']) ?></p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="push">
                <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                <button class="btn btn-ghost" type="submit">Push to linked customers</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= (int) $record['is_active'] === 1 ? 'deactivate' : 'activate' ?>">
                <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">
                <button class="btn btn-ghost" type="submit"><?= (int) $record['is_active'] === 1 ? 'Hide from fetch' : 'Make available' ?></button>
            </form>
        </div>
    <?php endif; ?>
</div>
<form class="filters" method="get" style="margin-top:16px">
    <div class="field"><label for="q">Search records</label><input id="q" name="q" value="<?= e($q) ?>"></div>
    <button class="btn btn-primary" type="submit">Search</button>
</form>
<div class="panel table-wrap">
    <table class="data">
        <thead><tr><th>Biller</th><th>Account</th><th>Period</th><th>Due</th><th>Amount</th><th>State</th></tr></thead>
        <tbody>
        <?php foreach ($page['rows'] as $row): ?>
            <tr>
                <td><?= e($row['biller_name']) ?></td>
                <td><a href="<?= e(url('admin/records.php?id=' . (int) $row['id'])) ?>"><?= e($row['account_number']) ?></a><br><span class="muted"><?= e($row['customer_name']) ?></span></td>
                <td><?= e($row['billing_period']) ?></td>
                <td><?= e(format_date((string) $row['due_date'])) ?></td>
                <td><?= e(money($row['amount'])) ?></td>
                <td><?= (int) $row['is_active'] === 1 ? 'Available' : 'Hidden' ?><?= (int) $row['is_sample'] === 1 ? ' · Sample' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= pager_links((int) $page['page'], (int) $page['pages'], 'admin/records.php') ?>
<?php render_shell_end('admin', 'records'); ?>

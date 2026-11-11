<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

require_admin();
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    $action = field('action');
    if ($action === 'add') {
        $code = strtolower(clean_text(field('code'), 40));
        $name = clean_text(field('name'), 160);
        $category = field('category');
        $region = clean_text(field('region'), 80);
        $description = clean_text(field('description'), 255);
        $bbpsId = clean_text(field('bbps_biller_id'), 40);
        $bbpsParam = clean_text(field('bbps_customer_param'), 80);
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $code)) {
            $errors[] = 'Use a short code with lowercase letters, numbers, or underscores.';
        }
        if ($name === '') {
            $errors[] = 'Enter the biller name.';
        }
        if (!in_array($category, ['lic', 'electricity'], true)) {
            $errors[] = 'Choose LIC or electricity.';
        }
        if (!$errors) {
            try {
                db()->prepare(
                    'INSERT INTO billers (code, name, category, region, description, bbps_biller_id, bbps_customer_param)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$code, $name, $category, $region, $description, $bbpsId, $bbpsParam]);
                audit('biller.add', 'biller', (int) db()->lastInsertId(), $code);
                flash('success', 'Biller added.');
                redirect('admin/billers.php');
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    $errors[] = 'That biller code already exists.';
                } else {
                    throw $e;
                }
            }
        }
    } elseif ($action === 'save_bbps') {
        $id = (int) field('biller_id');
        $bbpsId = clean_text(field('bbps_biller_id'), 40);
        $bbpsParam = clean_text(field('bbps_customer_param'), 80);
        db()->prepare('UPDATE billers SET bbps_biller_id = ?, bbps_customer_param = ? WHERE id = ?')
            ->execute([$bbpsId, $bbpsParam, $id]);
        audit('biller.bbps', 'biller', $id, $bbpsId);
        flash('success', 'BBPS mapping saved.');
        redirect('admin/billers.php');
    } elseif ($action === 'toggle') {
        $id = (int) field('biller_id');
        db()->prepare("UPDATE billers SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = ?")->execute([$id]);
        audit('biller.toggle', 'biller', $id, '');
        flash('success', 'Biller status updated. Inactive billers cannot be newly linked.');
        redirect('admin/billers.php');
    }
}

$billers = db()->query('SELECT * FROM billers ORDER BY category, name')->fetchAll();
render_shell_start('Billers', 'billers', 'admin', 'LIC and Bihar electricity companies customers can link.');
render_errors($errors);
?>
<div class="split">
    <form method="post" class="panel stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <h2 style="font-size:24px">Add a biller</h2>
        <div class="field"><label for="code">Code</label><input id="code" name="code" value="<?= old('code') ?>" placeholder="nbpdcl" required></div>
        <div class="field"><label for="name">Name</label><input id="name" name="name" value="<?= old('name') ?>" required></div>
        <div class="field"><label for="category">Category</label>
            <select id="category" name="category">
                <option value="lic"<?= selected_attr(field('category') ?: 'lic', 'lic') ?>>LIC</option>
                <option value="electricity"<?= selected_attr(field('category'), 'electricity') ?>>Electricity</option>
            </select>
        </div>
        <div class="field"><label for="region">Region</label><input id="region" name="region" value="<?= old('region') ?>"></div>
        <div class="field"><label for="description">Description</label><input id="description" name="description" value="<?= old('description') ?>"></div>
        <div class="field"><label for="bbps_biller_id">BBPS biller ID</label><input id="bbps_biller_id" name="bbps_biller_id" value="<?= old('bbps_biller_id') ?>" placeholder="NBPDCL000BHI01"></div>
        <div class="field"><label for="bbps_customer_param">Customer param name</label><input id="bbps_customer_param" name="bbps_customer_param" value="<?= old('bbps_customer_param') ?>" placeholder="CA Number"></div>
        <button class="btn btn-primary" type="submit">Add biller</button>
    </form>
    <div class="panel table-wrap">
        <table class="data">
            <thead><tr><th>Name</th><th>BBPS</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($billers as $biller): ?>
                <tr>
                    <td>
                        <?= e($biller['name']) ?><br>
                        <span class="muted"><?= e($biller['code']) ?> · <?= e(category_label((string) $biller['category'])) ?> · <?= e($biller['region']) ?></span>
                    </td>
                    <td>
                        <form method="post" class="stack" style="gap:8px;min-width:220px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_bbps">
                            <input type="hidden" name="biller_id" value="<?= (int) $biller['id'] ?>">
                            <input name="bbps_biller_id" value="<?= e((string) ($biller['bbps_biller_id'] ?? '')) ?>" placeholder="BBPS biller ID" aria-label="BBPS biller ID">
                            <input name="bbps_customer_param" value="<?= e((string) ($biller['bbps_customer_param'] ?? '')) ?>" placeholder="Customer param" aria-label="Customer param">
                            <button class="btn btn-sm btn-ghost" type="submit">Save mapping</button>
                        </form>
                    </td>
                    <td><span class="badge badge-<?= e($biller['status']) ?>"><?= e($biller['status']) ?></span></td>
                    <td>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="biller_id" value="<?= (int) $biller['id'] ?>">
                            <button class="btn btn-sm btn-ghost" type="submit"><?= $biller['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php render_shell_end('admin', 'billers'); ?>

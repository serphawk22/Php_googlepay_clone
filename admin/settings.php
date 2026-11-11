<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

require_admin();
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    $action = field('action') !== '' ? field('action') : 'save';

    $name = clean_text(field('merchant_name'), 80);
    $phone = clean_text(field('support_phone'), 20);
    $email = clean_text(field('support_email'), 190);
    $days = (int) field('default_remind_days');
    if ($name === '') {
        $errors[] = 'Enter the name customers see on SERP UPI.';
    }
    if ($phone !== '' && !preg_match('/^[0-9+ ]{8,20}$/', $phone)) {
        $errors[] = 'Enter a phone number using digits, spaces, or +.';
    }
    if ($email !== '' && !valid_email($email)) {
        $errors[] = 'Enter a valid support email.';
    }
    if (!in_array($days, remind_day_options(), true)) {
        $errors[] = 'Choose a default reminder day.';
    }

    $rzpEnabled = field('razorpay_enabled') === '1';
    $rzpKeyId = clean_text(field('razorpay_key_id'), 80);
    $rzpSecretPosted = str_replace("\0", '', (string) ($_POST['razorpay_key_secret'] ?? ''));
    $rzpSecret = $rzpSecretPosted !== '' ? trim($rzpSecretPosted) : setting('razorpay_key_secret');
    $rzpGeo = clean_text(field('razorpay_geocode'), 40);
    if ($rzpGeo === '') {
        $rzpGeo = '25.5941,85.1376';
    }
    if ($rzpEnabled && ($rzpKeyId === '' || $rzpSecret === '')) {
        $errors[] = 'To enable Razorpay, enter the Key ID and Key Secret.';
    }

    if (!$errors) {
        $pairs = [
            'merchant_name' => $name,
            'support_phone' => $phone,
            'support_email' => $email,
            'default_remind_days' => (string) $days,
            'show_sample_hints' => field('show_sample_hints') === '1' ? '1' : '0',
            'razorpay_enabled' => $rzpEnabled ? '1' : '0',
            'razorpay_key_id' => $rzpKeyId,
            'razorpay_key_secret' => $rzpSecret,
            'razorpay_geocode' => $rzpGeo,
        ];
        save_settings($pairs);
        audit('settings.save', 'settings', null, $name . ($rzpEnabled ? ' + Razorpay' : ''));
        flash('success', 'Settings saved.');
        redirect('admin/settings.php');
    }
}

render_shell_start('Settings', 'settings', 'admin', 'Name, reminders, support, and Razorpay.');
render_errors($errors);
?>
<div class="split">
    <form method="post" class="panel stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <h2 style="font-size:24px">Desk</h2>
        <div class="field">
            <label for="merchant_name">Name on SERP UPI</label>
            <input id="merchant_name" name="merchant_name" value="<?= old('merchant_name', setting('merchant_name', 'SERP UPI')) ?>" required>
        </div>
        <div class="form-row">
            <div class="field"><label for="support_phone">Support phone</label><input id="support_phone" name="support_phone" value="<?= old('support_phone', setting('support_phone')) ?>"></div>
            <div class="field"><label for="support_email">Support email</label><input id="support_email" name="support_email" type="email" value="<?= old('support_email', setting('support_email')) ?>"></div>
        </div>
        <div class="field">
            <label for="default_remind_days">Default reminder</label>
            <select id="default_remind_days" name="default_remind_days">
                <?php foreach (remind_day_options() as $days): ?>
                    <option value="<?= $days ?>"<?= selected_attr(field('default_remind_days') !== '' ? field('default_remind_days') : setting('default_remind_days', '3'), (string) $days) ?>><?= $days ?> days before the due date</option>
                <?php endforeach; ?>
            </select>
        </div>
        <label class="choice"><input type="checkbox" name="show_sample_hints" value="1"<?= setting('show_sample_hints', '1') === '1' ? ' checked' : '' ?>> Show trial account numbers to customers</label>

        <h2 style="font-size:24px;margin-top:8px">Razorpay (BBPS + payment gateway)</h2>
        <p class="muted">Uses one Key ID and Key Secret for bill fetch, Checkout, and BBPS settlement. Test keys (<code>rzp_test_</code>) do not hit the live BBPS network. Live keys do.</p>
        <label class="choice"><input type="checkbox" name="razorpay_enabled" value="1"<?= setting('razorpay_enabled') === '1' ? ' checked' : '' ?>> Enable Razorpay bill fetch and payment</label>
        <div class="field">
            <label for="razorpay_key_id">Key ID</label>
            <input id="razorpay_key_id" name="razorpay_key_id" value="<?= old('razorpay_key_id', setting('razorpay_key_id')) ?>" autocomplete="off" placeholder="rzp_test_...">
        </div>
        <div class="field">
            <label for="razorpay_key_secret">Key Secret<?= setting('razorpay_key_secret') !== '' ? ' <span class="muted">(leave blank to keep saved secret)</span>' : '' ?></label>
            <input id="razorpay_key_secret" name="razorpay_key_secret" type="password" value="" autocomplete="new-password" placeholder="<?= setting('razorpay_key_secret') !== '' ? '••••••••' : '' ?>">
        </div>
        <div class="field">
            <label for="razorpay_geocode">Device geocode (lat,long)</label>
            <input id="razorpay_geocode" name="razorpay_geocode" value="<?= old('razorpay_geocode', setting('razorpay_geocode', '25.5941,85.1376')) ?>">
        </div>
        <button class="btn btn-primary" type="submit">Save settings</button>
    </form>
    <aside class="panel stack">
        <h2 style="font-size:24px">Database</h2>
        <p>This site uses <strong><?= e(app_config('db_name', 'serp_secure_upi')) ?></strong> on <?= e(app_config('db_host')) ?>.</p>
        <h2 style="font-size:24px">Razorpay</h2>
        <?php if (RazorpayClient::isEnabled()): ?>
            <p class="notice">Razorpay bill fetch and Checkout are <strong>on</strong>.</p>
        <?php else: ?>
            <p class="muted">Razorpay is off. Fetch still uses local bill records.</p>
        <?php endif; ?>
        <h2 style="font-size:24px">Mapped billers</h2>
        <ul class="muted">
            <li>LIC of India → <code>LIFE00000NATL6</code> (Policy Number)</li>
            <li>NBPDCL → <code>NBPDCL000BHI01</code> (CA Number)</li>
            <li>SBPDCL → <code>SBPDCL000BHI01</code> (CA Number)</li>
        </ul>
        <p class="muted">Edit BBPS IDs on the Billers page if the biller uses a different customer parameter name.</p>
        <h2 style="font-size:24px">Phone app later</h2>
        <p class="muted">The pages are already sized for a phone. When you build the Capacitor app, point its WebView at this same website.</p>
        <p class="notice">Customers pay from their SERP wallet after entering the 6-digit PIN they created in Profile.</p>
    </aside>
</div>
<?php render_shell_end('admin', 'settings'); ?>

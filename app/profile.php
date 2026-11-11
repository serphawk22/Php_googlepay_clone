<?php
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$user = require_user();
$userId = (int) $user['id'];
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    $action = field('action');
    if ($action === 'profile') {
        $name = clean_text(field('full_name'), 120);
        $email = clean_text(field('email'), 190);
        $mobile = clean_text(field('mobile'), 10);
        if (!valid_name($name)) {
            $errors[] = 'Enter your name.';
        }
        if (!valid_email($email)) {
            $errors[] = 'Enter a valid email.';
        }
        if (!valid_mobile($mobile)) {
            $errors[] = 'Enter a 10-digit mobile number.';
        }
        if (!$errors) {
            $clash = db()->prepare('SELECT id, email, mobile FROM users WHERE (email = ? OR mobile = ?) AND id <> ? LIMIT 1');
            $clash->execute([$email, $mobile, $userId]);
            if ($clash->fetch()) {
                $errors[] = 'That email or mobile is already used by another account.';
            }
        }
        if (!$errors) {
            db()->prepare('UPDATE users SET full_name = ?, email = ?, mobile = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$name, $email, $mobile, $userId]);
            current_user(true);
            audit('profile.update', 'user', $userId, $email);
            flash('success', 'Profile saved.');
            redirect('app/profile.php');
        }
    } elseif ($action === 'password') {
        $current = field('current_password');
        $next = field('new_password');
        $again = field('new_password2');
        if (!password_verify($current, (string) $user['password_hash'])) {
            $errors[] = 'The current password is incorrect.';
        }
        $passwordError = valid_password($next);
        if ($passwordError !== null) {
            $errors[] = $passwordError;
        }
        if ($next !== $again) {
            $errors[] = 'The new passwords do not match.';
        }
        if (!$errors) {
            db()->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
                ->execute([password_hash($next, PASSWORD_DEFAULT), $userId]);
            audit('profile.password', 'user', $userId, '');
            flash('success', 'Password changed.');
            redirect('app/profile.php');
        }
    } elseif ($action === 'pin') {
        $next = field('serp_pin');
        $again = field('serp_pin2');
        $current = user_has_pin($user) ? field('serp_pin_current') : null;
        if ($next !== $again) {
            $errors[] = 'The two new PINs do not match.';
        } else {
            $result = save_user_pin($userId, $next, $current);
            if (!empty($result['ok'])) {
                current_user(true);
                flash('success', (string) $result['message']);
                redirect('app/profile.php');
            }
            $errors[] = (string) $result['message'];
        }
    }
}

render_shell_start('Profile', 'profile', 'user', 'This is the name and mobile on your SERP UPI account.');
render_errors($errors);
?>
<div class="split">
    <form method="post" class="panel stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="profile">
        <h2 style="font-size:26px">Your details</h2>
        <div class="field"><label for="full_name">Name</label><input id="full_name" name="full_name" value="<?= old('full_name', (string) $user['full_name']) ?>" required></div>
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= old('email', (string) $user['email']) ?>" required></div>
        <div class="field"><label for="mobile">Mobile</label><input id="mobile" name="mobile" inputmode="numeric" value="<?= old('mobile', (string) $user['mobile']) ?>" required></div>
        <button class="btn btn-primary" type="submit">Save profile</button>
    </form>
    <form method="post" class="panel stack" id="pin" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="pin">
        <h2 style="font-size:26px">SERP UPI PIN</h2>
        <p class="notice">This 6-digit PIN approves payments inside SERP UPI. It is stored as a hash. Do not reuse a bank, GPay, or PhonePe PIN.</p>
        <?php if (user_has_pin($user)): ?>
            <?php render_pin_field('serp_pin_current', 'Current PIN', 'serp_pin_current', false); ?>
        <?php endif; ?>
        <?php render_pin_field('serp_pin', user_has_pin($user) ? 'New PIN' : 'Choose a PIN', 'serp_pin', false); ?>
        <?php render_pin_field('serp_pin2', 'Confirm PIN', 'serp_pin2'); ?>
        <button class="btn btn-primary" type="submit"><?= user_has_pin($user) ? 'Change PIN' : 'Save PIN' ?></button>
    </form>
</div>
<div class="split" style="margin-top:16px">
    <form method="post" class="panel stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <h2 style="font-size:26px">Password</h2>
        <div class="field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
        <div class="field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" required></div>
        <div class="field"><label for="new_password2">Confirm new password</label><input id="new_password2" name="new_password2" type="password" autocomplete="new-password" required></div>
        <button class="btn btn-primary" type="submit">Change password</button>
    </form>
</div>
<?php render_shell_end('user', 'profile'); ?>

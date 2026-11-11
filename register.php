<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$existing = current_user();
if ($existing) {
    redirect(home_path($existing));
}

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_post();
    if (clean_text(field('company'), 80) !== '') {
        $errors[] = 'Could not create the account.';
    }
    $name = clean_text(field('full_name'), 120);
    $email = clean_text(field('email'), 190);
    $mobile = clean_text(field('mobile'), 10);
    $password = field('password');
    $password2 = field('password2');
    if (!valid_name($name)) {
        $errors[] = 'Enter your name, using letters only.';
    }
    if (!valid_email($email)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (!valid_mobile($mobile)) {
        $errors[] = 'Enter a 10-digit mobile number starting with 6, 7, 8, or 9.';
    }
    $passwordError = valid_password($password);
    if ($passwordError !== null) {
        $errors[] = $passwordError;
    }
    if ($password !== $password2) {
        $errors[] = 'The passwords do not match.';
    }
    if (!$errors) {
        $emailTaken = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $emailTaken->execute([$email]);
        $mobileTaken = db()->prepare('SELECT id FROM users WHERE mobile = ? LIMIT 1');
        $mobileTaken->execute([$mobile]);
        if ($emailTaken->fetch()) {
            $errors[] = 'An account with this email already exists.';
        }
        if ($mobileTaken->fetch()) {
            $errors[] = 'An account with this mobile number already exists.';
        }
    }
    if (!$errors) {
        $stmt = db()->prepare(
            'INSERT INTO users (full_name, email, mobile, password_hash, role, status) VALUES (?, ?, ?, ?, \'user\', \'active\')'
        );
        $stmt->execute([$name, $email, $mobile, password_hash($password, PASSWORD_DEFAULT)]);
        $user = ['id' => (int) db()->lastInsertId(), 'email' => $email, 'role' => 'user'];
        login_user($user);
        audit('register', 'user', (int) $user['id'], $email);
        flash('success', 'Welcome to SERP UPI. Link a policy or an electricity connection to fetch your first bill.');
        redirect('app/index.php');
    }
}

render_public_start('Create account');
?>
<div class="auth-wrap">
    <div class="auth-card stack">
        <p class="eyebrow">SERP UPI</p>
        <h1>Create your account</h1>
        <p class="muted">Use it for LIC premiums and Bihar electricity bills.</p>
        <?php render_flashes(); render_errors($errors); ?>
        <form method="post">
            <?= csrf_field() ?>
            <div class="hp" aria-hidden="true"><label>Company<input name="company" tabindex="-1" autocomplete="off"></label></div>
            <div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" value="<?= old('full_name') ?>" required></div>
            <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username" value="<?= old('email') ?>" required></div>
            <div class="field"><label for="mobile">Mobile</label><input id="mobile" name="mobile" inputmode="numeric" autocomplete="tel" value="<?= old('mobile') ?>" required></div>
            <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" required><p class="help">At least 8 characters, with a letter and a number.</p></div>
            <div class="field"><label for="password2">Confirm password</label><input id="password2" name="password2" type="password" autocomplete="new-password" required></div>
            <button class="btn btn-primary btn-block" type="submit">Create account</button>
        </form>
        <p>Already registered? <a href="<?= e(url('login.php')) ?>">Sign in</a></p>
    </div>
</div>
<?php render_public_end(); ?>

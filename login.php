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
    $locked = (int) ($_SESSION['lock_until'] ?? 0);
    if ($locked > time()) {
        $errors[] = 'Too many attempts. Try again in a few minutes.';
    } else {
        $email = clean_text(field('email'), 190);
        $password = field('password');
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch() ?: null;
        $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
        $ok = password_verify($password, $user['password_hash'] ?? $dummy);
        if (!$user || !$ok) {
            $_SESSION['fails'] = (int) ($_SESSION['fails'] ?? 0) + 1;
            if ((int) $_SESSION['fails'] >= 5) {
                $_SESSION['lock_until'] = time() + 600;
                $_SESSION['fails'] = 0;
            }
            $errors[] = 'Email or password is incorrect.';
        } elseif ($user['status'] !== 'active') {
            $errors[] = 'This account is suspended.';
        } else {
            login_user($user);
            flash('success', 'Signed in.');
            redirect(home_path($user));
        }
    }
}

render_public_start('Sign in');
?>
<div class="auth-wrap">
    <div class="auth-card stack">
        <p class="eyebrow">SERP UPI</p>
        <h1>Sign in</h1>
        <?php if (isset($_GET['installed'])): ?>
            <div class="flash flash-success">Installation finished. Sign in with the administrator account you just created.</div>
        <?php endif; ?>
        <?php if (isset($_GET['out'])): ?>
            <div class="flash flash-info">You have signed out.</div>
        <?php endif; ?>
        <?php render_flashes(); render_errors($errors); ?>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="username" value="<?= old('email') ?>" required>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>
            <button class="btn btn-primary btn-block" type="submit">Sign in</button>
        </form>
        <p>New here? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>
    </div>
</div>
<?php render_public_end(); ?>

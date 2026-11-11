<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
$billers = active_billers();
$preview = db()->query(
    "SELECT br.amount, br.late_fee, br.due_date, br.customer_name, bi.name AS biller_name
     FROM biller_records br
     INNER JOIN billers bi ON bi.id = br.biller_id
     WHERE br.is_sample = 1 AND br.is_active = 1
     ORDER BY br.due_date ASC
     LIMIT 3"
)->fetchAll();

render_public_start('SERP UPI');
?>
<div class="wrap page-gap">
    <section class="hero">
        <div>
            <p class="eyebrow">Bihar bill desk</p>
            <h1>Premiums and power bills, remembered before they are late.</h1>
            <p class="lead">SERP UPI keeps an LIC policy and a Bihar electricity connection together. It fetches the current bill, reminds you before the due date, and takes the payment here with your 6-digit SERP UPI PIN.</p>
            <div class="cluster">
                <?php if ($user): ?>
                    <a class="btn btn-primary" href="<?= e(url(home_path($user))) ?>">Open your desk</a>
                <?php else: ?>
                    <a class="btn btn-primary" href="<?= e(url('register.php')) ?>">Create an account</a>
                    <a class="btn btn-ghost" href="<?= e(url('login.php')) ?>" style="background:transparent;color:#f6f1e7;border-color:rgba(246,241,231,.35)">Sign in</a>
                <?php endif; ?>
            </div>
        </div>
        <aside class="phone" aria-label="Example dues">
            <div class="phone-top">Due soon</div>
            <?php if (!$preview): ?>
                <div class="phone-bill"><span>No sample bills yet</span><strong>Link an account</strong><em>After install, trial numbers are ready to fetch.</em></div>
            <?php endif; ?>
            <?php foreach ($preview as $row): ?>
                <?php
                $fake = [
                    'amount' => $row['amount'],
                    'late_fee' => $row['late_fee'],
                    'due_date' => $row['due_date'],
                    'status' => ((string) $row['due_date'] < date('Y-m-d')) ? 'overdue' : 'unpaid',
                ];
                ?>
                <div class="phone-bill">
                    <span><?= e($row['biller_name']) ?></span>
                    <strong><?= e(money(amount_due($fake))) ?></strong>
                    <em><?= e($row['customer_name']) ?> · <?= e(due_label((string) $row['due_date'])) ?></em>
                </div>
            <?php endforeach; ?>
        </aside>
    </section>

    <section class="section" id="how">
        <div class="section-head">
            <h2>How a bill moves</h2>
            <p class="muted">The same four steps work for a policy and for a consumer number.</p>
        </div>
        <div class="grid-2">
            <article class="card"><div class="step-no">1</div><h3>Link the account</h3><p class="muted">Add an LIC policy number, or an NBPDCL / SBPDCL consumer number.</p></article>
            <article class="card"><div class="step-no">2</div><h3>Fetch the bill</h3><p class="muted">SERP UPI reads the latest bill held for that number and puts it on your desk.</p></article>
            <article class="card"><div class="step-no">3</div><h3>Pay with your PIN</h3><p class="muted">Enter the 6-digit SERP UPI PIN you created here. The amount comes out of your SERP wallet.</p></article>
            <article class="card"><div class="step-no">4</div><h3>Keep the receipt</h3><p class="muted">A correct PIN marks the bill paid at once, and the receipt stays in your payment history.</p></article>
        </div>
    </section>

    <section class="section" id="billers">
        <div class="section-head">
            <h2>Billers on this desk</h2>
            <p class="muted">More connections can be added later from the admin panel. Customers pay them inside SERP UPI.</p>
        </div>
        <div class="grid-3">
            <?php foreach ($billers as $biller): ?>
                <article class="card">
                    <span class="badge"><?= e(category_label((string) $biller['category'])) ?></span>
                    <h3 style="margin-top:10px"><?= e($biller['name']) ?></h3>
                    <p class="muted"><?= e($biller['region']) ?></p>
                    <p style="margin-top:8px"><?= e($biller['description']) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="section safety" id="safety">
        <h2>Your SERP UPI PIN stays in this app.</h2>
        <p>You choose a 6-digit PIN in Profile. SERP UPI stores only a hash of it and asks for it when you add money or pay a bill. Do not type a bank PIN, a GPay PIN, or a PhonePe PIN here. This app does not connect to those apps, and it does not debit a bank account.</p>
    </section>
</div>
<?php render_public_end(); ?>

(function () {
  var TOKEN_KEY = 'serp_api_token';
  var API_KEY = 'serp_api_base';

  function defaultApi() {
    return window.SERP_API || window.SERP_LOCAL_API || 'http://10.0.2.2/serpupi/api/index.php';
  }

  var state = {
    screen: 'boot',
    token: localStorage.getItem(TOKEN_KEY) || '',
    apiBase: localStorage.getItem(API_KEY) || defaultApi(),
    user: null,
    home: null,
    accounts: [],
    billers: [],
    bills: [],
    billStatus: 'due',
    currentBill: null,
    form: {},
    error: '',
    notice: '',
    busy: false
  };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function api(action, payload) {
    var base = state.apiBase;
    var url = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'action=' + encodeURIComponent(action);
    var body = Object.assign({}, payload || {});
    if (state.token) body.token = state.token;
    var headers = { 'Content-Type': 'application/json' };
    if (state.token) headers.Authorization = 'Bearer ' + state.token;
    return fetch(url, {
      method: 'POST',
      headers: headers,
      body: JSON.stringify(body)
    }).then(function (res) {
      return res.json().catch(function () {
        return { success: false, message: 'Server did not return JSON. Check the API URL.' };
      });
    }).then(function (data) {
      if (!data || !data.success) {
        var err = new Error((data && data.message) || 'Request failed');
        err.payload = data;
        if (data && (data.message || '').toLowerCase().indexOf('sign in') >= 0) {
          state.token = '';
          localStorage.removeItem(TOKEN_KEY);
        }
        throw err;
      }
      return data;
    });
  }

  function setScreen(name) {
    state.screen = name;
    state.error = '';
    render();
  }

  function flash(msg, isError) {
    state.error = isError ? msg : '';
    state.notice = isError ? '' : msg;
    render();
  }

  function withBusy(fn) {
    state.busy = true;
    render();
    return Promise.resolve()
      .then(fn)
      .catch(function (err) {
        flash(err.message || 'Something went wrong.', true);
      })
      .finally(function () {
        state.busy = false;
        render();
      });
  }

  function loadHome() {
    return api('home').then(function (data) {
      state.user = data.user;
      state.home = data;
      setScreen('home');
    });
  }

  function loadAccounts() {
    return Promise.all([api('accounts'), api('billers')]).then(function (parts) {
      state.accounts = parts[0].accounts || [];
      state.billers = parts[1].billers || [];
      setScreen('accounts');
    });
  }

  function loadBills(status) {
    state.billStatus = status || state.billStatus || 'due';
    return api('bills', { status: state.billStatus }).then(function (data) {
      state.bills = data.bills || [];
      setScreen('bills');
    });
  }

  function openBill(id) {
    return api('bill', { bill_id: id }).then(function (data) {
      state.currentBill = data;
      setScreen('bill');
    });
  }

  function shell(content, active) {
    var user = state.user;
    var nav = user ? (
      '<nav class="nav">' +
        '<button type="button" data-go="home" class="' + (active === 'home' ? 'active' : '') + '">Home</button>' +
        '<button type="button" data-go="accounts" class="' + (active === 'accounts' ? 'active' : '') + '">Accounts</button>' +
        '<button type="button" data-go="bills" class="' + (active === 'bills' || active === 'bill' ? 'active' : '') + '">Bills</button>' +
        '<button type="button" data-go="more" class="' + (active === 'more' ? 'active' : '') + '">More</button>' +
      '</nav>'
    ) : '';
    var alerts = '';
    if (state.error) alerts += '<div class="flash flash-error">' + esc(state.error) + '</div>';
    if (state.notice) alerts += '<div class="flash flash-ok">' + esc(state.notice) + '</div>';
    if (state.busy) alerts += '<div class="flash">Working…</div>';
    return alerts + content + nav;
  }

  function renderLogin() {
    return shell(
      '<div class="top"><div><h1 class="brand">SERP UPI</h1><p class="sub">Sign in to fetch LIC and Bihar electricity bills.</p></div></div>' +
      '<div class="card stack">' +
        '<div class="field"><label>API URL</label><input id="apiUrl" value="' + esc(state.apiBase) + '"></div>' +
        '<div class="field"><label>Email</label><input id="email" type="email" autocomplete="username" value="' + esc(state.form.email || '') + '"></div>' +
        '<div class="field"><label>Password</label><input id="password" type="password" autocomplete="current-password"></div>' +
        '<button class="btn btn-primary btn-block" type="button" id="loginBtn">Sign in</button>' +
        '<p class="tiny">Same accounts as the website. Point API URL at your XAMPP / live server.</p>' +
      '</div>',
      'login'
    );
  }

  function renderHome() {
    var h = state.home || {};
    var bills = (h.bills || []).map(function (b) {
      return (
        '<article class="card" data-bill="' + b.id + '">' +
          '<div class="row"><div><h3>' + esc(b.biller_name) + '</h3><div class="tiny">' + esc(b.account_number) + ' · ' + esc(b.due_label) + '</div></div>' +
          '<div class="amount">' + esc(b.amount_due_label) + '</div></div>' +
          '<div class="row" style="margin-top:10px"><span class="badge badge-' + esc(b.status) + '">' + esc(b.status_label) + '</span>' +
          '<button class="btn btn-sm btn-ghost" type="button" data-bill="' + b.id + '">Open</button></div>' +
        '</article>'
      );
    }).join('') || '<div class="card empty">No open bills. Link an account and fetch.</div>';
    return shell(
      '<div class="top"><div><h1 class="brand">Hello' + (state.user ? ', ' + esc((state.user.full_name || '').split(' ')[0]) : '') + '</h1><p class="sub">LIC premiums and Bihar electricity</p></div>' +
      '<button class="btn btn-sm btn-ghost" type="button" id="fetchAll">Fetch</button></div>' +
      '<section class="hero"><span>Open bills</span><strong>' + esc(h.due_total_label || '₹0.00') + '</strong><div class="tiny">' + esc(String(h.accounts || 0)) + ' linked account(s) · wallet ' + esc((state.user && state.user.wallet_label) || '₹0.00') + '</div></section>' +
      bills,
      'home'
    );
  }

  function renderAccounts() {
    var list = state.accounts.map(function (a) {
      return (
        '<article class="card">' +
          '<div class="row"><div><h3>' + esc(a.nickname || a.biller_name) + '</h3><div class="tiny">' + esc(a.biller_name) + ' · ' + esc(a.account_number) + '</div></div>' +
          '<button class="btn btn-sm btn-primary" type="button" data-fetch="' + a.id + '">Fetch</button></div>' +
          '<div class="tiny" style="margin-top:8px">' + esc(a.holder_name) + '</div>' +
        '</article>'
      );
    }).join('') || '<div class="card empty">No linked accounts yet.</div>';
    var options = state.billers.map(function (b) {
      return '<option value="' + b.id + '">' + esc(b.name) + '</option>';
    }).join('');
    return shell(
      '<div class="top"><div><h1 class="brand">Accounts</h1><p class="sub">Link LIC or Bihar consumer numbers.</p></div></div>' +
      '<div class="card stack">' +
        '<div class="field"><label>Biller</label><select id="billerId">' + options + '</select></div>' +
        '<div class="field"><label>Policy / CA number</label><input id="accountNumber" inputmode="numeric"></div>' +
        '<div class="field"><label>Name on bill</label><input id="holderName"></div>' +
        '<div class="field"><label>Nickname (optional)</label><input id="nickname"></div>' +
        '<button class="btn btn-primary btn-block" type="button" id="linkBtn">Link account</button>' +
      '</div>' + list,
      'accounts'
    );
  }

  function renderBills() {
    var tabs = ['due', 'paid', 'all'].map(function (s) {
      return '<button class="btn btn-sm ' + (state.billStatus === s ? 'btn-primary' : 'btn-ghost') + '" type="button" data-status="' + s + '">' + s + '</button>';
    }).join(' ');
    var list = state.bills.map(function (b) {
      return (
        '<article class="card" data-bill="' + b.id + '">' +
          '<div class="row"><div><h3>' + esc(b.biller_name) + '</h3><div class="tiny">' + esc(b.billing_period) + ' · ' + esc(b.due_label) + '</div></div>' +
          '<div class="amount">' + esc(b.amount_due_label) + '</div></div>' +
        '</article>'
      );
    }).join('') || '<div class="card empty">No bills in this list.</div>';
    return shell(
      '<div class="top"><div><h1 class="brand">Bills</h1><p class="sub">Fetched from your linked accounts.</p></div></div>' +
      '<div class="row" style="margin-bottom:12px">' + tabs + '</div>' + list,
      'bills'
    );
  }

  function renderBill() {
    var data = state.currentBill || {};
    var b = data.bill || {};
    var pay = '';
    if (b.status !== 'paid') {
      pay =
        '<div class="card stack">' +
          (data.razorpay_enabled
            ? '<button class="btn btn-primary btn-block" type="button" id="rzpBtn">Pay with Razorpay</button>'
            : '') +
          '<div class="field"><label>SERP UPI PIN</label><input id="pin" inputmode="numeric" maxlength="6" autocomplete="off"></div>' +
          '<button class="btn btn-ghost btn-block" type="button" id="walletPay">Pay from wallet</button>' +
        '</div>';
    }
    return shell(
      '<div class="top"><div><h1 class="brand">Bill</h1><p class="sub">' + esc(b.biller_name) + '</p></div>' +
      '<button class="btn btn-sm btn-ghost" type="button" data-go="bills">Back</button></div>' +
      '<div class="card stack">' +
        '<div class="row"><span>Amount due</span><strong class="amount">' + esc(b.amount_due_label) + '</strong></div>' +
        '<div class="row"><span class="muted">Account</span><span>' + esc(b.account_number) + '</span></div>' +
        '<div class="row"><span class="muted">Period</span><span>' + esc(b.billing_period) + '</span></div>' +
        '<div class="row"><span class="muted">Due</span><span>' + esc(b.due_label) + '</span></div>' +
        '<div class="row"><span class="muted">Status</span><span class="badge badge-' + esc(b.status) + '">' + esc(b.status_label) + '</span></div>' +
      '</div>' + pay,
      'bill'
    );
  }

  function renderMore() {
    return shell(
      '<div class="top"><div><h1 class="brand">More</h1><p class="sub">Wallet and API settings</p></div></div>' +
      '<div class="card stack">' +
        '<div class="row"><span>Wallet</span><strong>' + esc((state.user && state.user.wallet_label) || '₹0.00') + '</strong></div>' +
        '<div class="field"><label>API URL</label><input id="apiUrl" value="' + esc(state.apiBase) + '"></div>' +
        '<button class="btn btn-ghost btn-block" type="button" id="saveApi">Save API URL</button>' +
        '<button class="btn btn-primary btn-block" type="button" id="logoutBtn">Sign out</button>' +
      '</div>',
      'more'
    );
  }

  function render() {
    var root = document.getElementById('app');
    if (!root) return;
    var html = '';
    if (state.screen === 'login' || state.screen === 'boot') html = renderLogin();
    else if (state.screen === 'home') html = renderHome();
    else if (state.screen === 'accounts') html = renderAccounts();
    else if (state.screen === 'bills') html = renderBills();
    else if (state.screen === 'bill') html = renderBill();
    else if (state.screen === 'more') html = renderMore();
    else html = renderLogin();
    root.innerHTML = html;
  }

  function payRazorpay(order) {
    return new Promise(function (resolve, reject) {
      if (!window.Razorpay) {
        reject(new Error('Razorpay Checkout is not loaded.'));
        return;
      }
      var rzp = new Razorpay({
        key: order.key,
        amount: order.amount,
        currency: order.currency || 'INR',
        name: order.name || 'SERP UPI',
        description: order.description || 'Bill payment',
        order_id: order.order_id,
        prefill: order.prefill || {},
        theme: { color: '#0e7a4b' },
        handler: function (response) {
          resolve(response);
        }
      });
      rzp.on('payment.failed', function (resp) {
        reject(new Error((resp && resp.error && resp.error.description) || 'Payment failed'));
      });
      rzp.open();
    });
  }

  document.addEventListener('click', function (event) {
    var t = event.target.closest('[data-go],[data-bill],[data-fetch],[data-status],button');
    if (!t) return;

    if (t.id === 'loginBtn') {
      withBusy(function () {
        var apiUrl = (document.getElementById('apiUrl') || {}).value || state.apiBase;
        state.apiBase = apiUrl.replace(/\/?$/, '').replace(/index\.php$/, 'index.php');
        if (state.apiBase.indexOf('index.php') < 0) state.apiBase += (state.apiBase.slice(-1) === '/' ? '' : '/') + 'index.php';
        // Keep trailing path clean
        if (!/index\.php$/i.test(state.apiBase)) {
          state.apiBase = state.apiBase.replace(/\/?$/, '/') + 'index.php';
        }
        localStorage.setItem(API_KEY, state.apiBase);
        state.form.email = (document.getElementById('email') || {}).value || '';
        return api('login', {
          email: state.form.email,
          password: (document.getElementById('password') || {}).value || ''
        }).then(function (data) {
          state.token = data.token;
          state.user = data.user;
          localStorage.setItem(TOKEN_KEY, state.token);
          state.notice = 'Signed in.';
          return loadHome();
        });
      });
      return;
    }

    if (t.getAttribute('data-go') === 'home') { withBusy(loadHome); return; }
    if (t.getAttribute('data-go') === 'accounts') { withBusy(loadAccounts); return; }
    if (t.getAttribute('data-go') === 'bills') { withBusy(function () { return loadBills(state.billStatus); }); return; }
    if (t.getAttribute('data-go') === 'more') { setScreen('more'); return; }

    if (t.id === 'fetchAll') {
      withBusy(function () {
        return api('fetch_all').then(function (data) {
          state.notice = data.message || 'Fetch finished.';
          return loadHome();
        });
      });
      return;
    }

    if (t.id === 'linkBtn') {
      withBusy(function () {
        return api('link_account', {
          biller_id: (document.getElementById('billerId') || {}).value,
          account_number: (document.getElementById('accountNumber') || {}).value,
          holder_name: (document.getElementById('holderName') || {}).value,
          nickname: (document.getElementById('nickname') || {}).value,
          remind_days: 3
        }).then(function (data) {
          state.notice = data.message + (data.fetch && data.fetch.message ? ' ' + data.fetch.message : '');
          return loadAccounts();
        });
      });
      return;
    }

    if (t.getAttribute('data-fetch')) {
      var accountId = t.getAttribute('data-fetch');
      withBusy(function () {
        return api('fetch_bill', { account_id: accountId }).then(function (data) {
          state.notice = data.message || 'Fetched.';
          if (data.bill_id) return openBill(data.bill_id);
          return loadAccounts();
        });
      });
      return;
    }

    if (t.getAttribute('data-status')) {
      withBusy(function () { return loadBills(t.getAttribute('data-status')); });
      return;
    }

    if (t.getAttribute('data-bill')) {
      withBusy(function () { return openBill(t.getAttribute('data-bill')); });
      return;
    }

    if (t.id === 'walletPay') {
      withBusy(function () {
        return api('pay_wallet', {
          bill_id: state.currentBill.bill.id,
          pin: (document.getElementById('pin') || {}).value || ''
        }).then(function (data) {
          state.notice = data.message || 'Paid.';
          return openBill(state.currentBill.bill.id);
        });
      });
      return;
    }

    if (t.id === 'rzpBtn') {
      withBusy(function () {
        return api('razorpay_order', { bill_id: state.currentBill.bill.id }).then(function (order) {
          return payRazorpay(order).then(function (response) {
            return api('razorpay_confirm', {
              razorpay_order_id: response.razorpay_order_id,
              razorpay_payment_id: response.razorpay_payment_id,
              razorpay_signature: response.razorpay_signature
            });
          });
        }).then(function (data) {
          state.notice = data.message || 'Paid with Razorpay.';
          return openBill(state.currentBill.bill.id);
        });
      });
      return;
    }

    if (t.id === 'saveApi') {
      var next = (document.getElementById('apiUrl') || {}).value || '';
      if (!/index\.php$/i.test(next)) next = next.replace(/\/?$/, '/') + 'index.php';
      state.apiBase = next;
      localStorage.setItem(API_KEY, state.apiBase);
      flash('API URL saved.', false);
      return;
    }

    if (t.id === 'logoutBtn') {
      withBusy(function () {
        return api('logout').catch(function () { return null; }).then(function () {
          state.token = '';
          state.user = null;
          localStorage.removeItem(TOKEN_KEY);
          setScreen('login');
        });
      });
    }
  });

  function boot() {
    render();
    if (!state.token) {
      setScreen('login');
      return;
    }
    withBusy(function () {
      return api('me').then(function (data) {
        state.user = data.user;
        return loadHome();
      }).catch(function () {
        state.token = '';
        localStorage.removeItem(TOKEN_KEY);
        setScreen('login');
      });
    });
  }

  boot();
})();

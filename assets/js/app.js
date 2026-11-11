(function () {
  function closeNav() {
    document.body.classList.remove('nav-open');
  }

  document.querySelectorAll('[data-nav-toggle]').forEach(function (node) {
    node.addEventListener('click', function () {
      document.body.classList.toggle('nav-open');
    });
  });

  document.querySelectorAll('.sidebar a').forEach(function (link) {
    link.addEventListener('click', closeNav);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closeNav();
    }
  });

  document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var value = button.getAttribute('data-copy') || '';
      var original = button.textContent;
      function done(label) {
        button.textContent = label;
        window.setTimeout(function () {
          button.textContent = original;
        }, 1400);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(function () {
          done('Copied');
        }).catch(function () {
          done('Copy failed');
        });
        return;
      }
      done('Copy failed');
    });
  });

  document.querySelectorAll('[data-pin-pad]').forEach(function (pad) {
    var id = pad.getAttribute('data-pin-pad');
    var input = document.getElementById(id);
    if (!input) {
      return;
    }
    function paint() {
      var digits = String(input.value || '').replace(/\D/g, '').slice(0, 6);
      if (input.value !== digits) {
        input.value = digits;
      }
      var dots = document.querySelector('[data-pin-dots="' + id + '"]');
      if (!dots) {
        return;
      }
      dots.querySelectorAll('span').forEach(function (span, index) {
        span.classList.toggle('is-on', index < digits.length);
      });
    }
    input.addEventListener('input', paint);
    pad.addEventListener('click', function (event) {
      var button = event.target.closest('[data-pin-key]');
      if (!button) {
        return;
      }
      var key = button.getAttribute('data-pin-key');
      var value = String(input.value || '').replace(/\D/g, '');
      if (key === 'clear') {
        value = '';
      } else if (key === 'back') {
        value = value.slice(0, -1);
      } else if (value.length < 6) {
        value += key;
      }
      input.value = value;
      paint();
    });
    paint();
  });

  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      var source = event.submitter || form;
      var message = (source.getAttribute && source.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
      if (message && !window.confirm(message)) {
        event.preventDefault();
        return;
      }
      var button = event.submitter || form.querySelector('[type="submit"]');
      if (button) {
        button.disabled = true;
      }
    });
  });
})();

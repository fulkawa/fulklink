<?php
/** @var array $old */
/** @var string $error */
$mode = (string) ($old['mode'] ?? 'email');
?>
<div class="auth-wrap">
  <div class="auth-card">
    <h1 class="auth-h">注册</h1>
    <p class="auth-sub">买授权、查订单、下载更新包，都要用这个账号。</p>

    <?php if (($error ?? '') !== ''): ?>
      <div class="alert err" style="margin-bottom:16px"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="auth-tabs">
      <button type="button" class="auth-tab <?= $mode === 'email' ? 'on' : '' ?>" data-mode="email">邮箱注册</button>
      <button type="button" class="auth-tab <?= $mode === 'phone' ? 'on' : '' ?>" data-mode="phone">手机号注册</button>
    </div>

    <form method="post" action="<?= path('/register') ?>" id="regForm" autocomplete="on">
      <?= \App\Auth::csrfField() ?>
      <input type="hidden" name="mode" id="modeInput" value="<?= e($mode) ?>">

      <div data-pane="email">
        <div class="field">
          <label for="email">邮箱</label>
          <input class="input" type="email" id="email" name="email"
                 value="<?= e($old['email'] ?? '') ?>" placeholder="you@example.com" autocomplete="email">
          <div class="field-tip">验证码发到这里，收不到先看垃圾邮件。</div>
        </div>
      </div>

      <div data-pane="phone">
        <div class="field">
          <label for="phone">手机号</label>
          <input class="input" type="tel" id="phone" name="phone"
                 value="<?= e($old['phone'] ?? '') ?>" placeholder="11 位手机号" autocomplete="tel">
          <div class="field-tip">只用来收验证码和找回账号，不发别的。</div>
        </div>
      </div>

      <div class="field">
        <label for="code">验证码</label>
        <div class="code-row">
          <input class="input" type="text" id="code" name="code" inputmode="numeric"
                 maxlength="6" placeholder="6 位数字" autocomplete="one-time-code">
          <button type="button" class="btn ghost" id="sendCode">获取验证码</button>
        </div>
      </div>

      <div class="field">
        <label for="password">密码</label>
        <input class="input" type="password" id="password" name="password"
               placeholder="至少 8 位，别全用数字" autocomplete="new-password">
      </div>

      <div class="field">
        <label for="password2">再输一次密码</label>
        <input class="input" type="password" id="password2" name="password2"
               placeholder="两次要一样" autocomplete="new-password">
      </div>

      <label class="checkline" style="margin-bottom:18px">
        <input type="checkbox" name="agree" value="1">
        <span>我读过并同意 <a href="<?= path('/legal/terms') ?>" target="_blank">服务条款</a> 和
          <a href="<?= path('/legal/privacy') ?>" target="_blank">隐私说明</a></span>
      </label>

      <button class="btn primary block" type="submit" style="width:100%">注册并登录</button>
    </form>

    <div class="auth-foot">
      已经有账号？<a href="<?= path('/login') ?>">去登录</a>
    </div>
  </div>
</div>

<script>
(function () {
  var modeInput = document.getElementById('modeInput');
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.auth-tab'));
  var panes = Array.prototype.slice.call(document.querySelectorAll('[data-pane]'));

  function apply(m) {
    modeInput.value = m;
    tabs.forEach(function (t) { t.classList.toggle('on', t.dataset.mode === m); });
    panes.forEach(function (p) { p.style.display = p.dataset.pane === m ? '' : 'none'; });
  }
  tabs.forEach(function (t) {
    t.addEventListener('click', function () { apply(t.dataset.mode); });
  });
  apply(modeInput.value || 'email');

  var btn = document.getElementById('sendCode');
  var csrf = document.querySelector('input[name="_csrf"]').value;
  var timer = null;

  function tick(left) {
    btn.disabled = left > 0;
    btn.textContent = left > 0 ? left + ' 秒后重发' : '获取验证码';
    if (left > 0) {
      timer = setTimeout(function () { tick(left - 1); }, 1000);
    }
  }
  function note(text, bad) {
    var el = document.getElementById('regNote');
    if (!el) {
      el = document.createElement('div');
      el.id = 'regNote';
      el.style.margin = '0 0 14px';
      el.style.fontSize = '13px';
      btn.closest('.field').appendChild(el);
    }
    el.style.color = bad ? 'var(--err)' : 'var(--ok)';
    el.textContent = text;
  }

  btn.addEventListener('click', function () {
    var m = modeInput.value;
    var pane = document.querySelector('[data-pane="' + m + '"]');
    var input = pane ? pane.querySelector('input') : null;
    var target = input ? input.value.trim() : '';
    if (!target) {
      note(m === 'phone' ? '先填手机号。' : '先填邮箱。', true);
      return;
    }
    btn.disabled = true;
    btn.textContent = '发送中…';
    note('');

    fetch('<?= path('/captcha/send') ?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ target: target, purpose: 'register', _csrf: csrf })
    }).then(function (r) { return r.json().catch(function () { return { ok: false, message: '服务没有正常返回，稍后再试。' }; }); })
      .then(function (j) {
        if (j.ok) {
          note(j.message || '已发送。');
          tick(60);
        } else {
          note(j.message || '没发出去，稍后再试。', true);
          btn.disabled = false;
          btn.textContent = '获取验证码';
        }
      })
      .catch(function () {
        note('网络断了，检查一下再试。', true);
        btn.disabled = false;
        btn.textContent = '获取验证码';
      });
  });
})();
</script>

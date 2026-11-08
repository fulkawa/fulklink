<?php
/** @var string $ident */
/** @var string $error */
?>
<div class="auth-wrap">
  <div class="auth-card">
    <h1 class="auth-h">忘记密码</h1>
    <p class="auth-sub">填注册时的邮箱或手机号，收验证码后直接设新密码。</p>

    <?php if (($error ?? '') !== ''): ?>
      <div class="alert err" style="margin-bottom:16px"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= path('/forgot') ?>" autocomplete="on">
      <?= \App\Auth::csrfField() ?>

      <div class="field">
        <label for="ident">邮箱或手机号</label>
        <input class="input" type="text" id="ident" name="ident"
               value="<?= e($ident ?? '') ?>" placeholder="you@example.com 或 11 位手机号"
               autocomplete="username">
      </div>

      <div class="field">
        <label for="code">验证码</label>
        <div class="code-row">
          <input class="input" type="text" id="code" name="code" inputmode="numeric"
                 maxlength="6" placeholder="6 位数字" autocomplete="one-time-code">
          <button type="button" class="btn ghost" id="sendCode">获取验证码</button>
        </div>
        <div class="field-tip">收不到先看垃圾邮件，一分钟只能发一条。</div>
      </div>

      <div class="field">
        <label for="password">新密码</label>
        <input class="input" type="password" id="password" name="password"
               placeholder="至少 8 位，别全用数字" autocomplete="new-password">
      </div>

      <div class="field">
        <label for="password2">再输一次新密码</label>
        <input class="input" type="password" id="password2" name="password2"
               placeholder="两次要一样" autocomplete="new-password">
      </div>

      <button class="btn primary block" type="submit" style="width:100%">重置密码</button>
    </form>

    <div class="auth-foot">
      想起密码了？<a href="<?= path('/login') ?>">去登录</a>
    </div>
  </div>
</div>

<script>
(function () {
  var btn = document.getElementById('sendCode');
  var ident = document.getElementById('ident');
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
    var el = document.getElementById('forgotNote');
    if (!el) {
      el = document.createElement('div');
      el.id = 'forgotNote';
      el.style.margin = '6px 0 0';
      el.style.fontSize = '13px';
      btn.closest('.field').appendChild(el);
    }
    el.style.color = bad ? 'var(--err)' : 'var(--ok)';
    el.textContent = text;
  }

  btn.addEventListener('click', function () {
    var target = ident.value.trim();
    if (!target) {
      note('先填邮箱或手机号。', true);
      return;
    }
    btn.disabled = true;
    btn.textContent = '发送中…';
    note('');

    fetch('<?= path('/captcha/send') ?>', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ target: target, purpose: 'reset', _csrf: csrf })
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

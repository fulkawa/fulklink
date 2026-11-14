<?php
/** @var string $ident */
/** @var string $error */
$msg = (string) (new \App\Request())->query('msg', '');
?>
<div class="auth-wrap">
  <div class="auth-card">
    <h1 class="auth-h">登录</h1>
    <p class="auth-sub">用注册时的邮箱或手机号登录。</p>

    <?php if ($msg !== ''): ?>
      <div class="alert" style="margin-bottom:16px;border-color:rgba(63,185,80,.35);color:var(--ok)"><?= e($msg) ?></div>
    <?php endif; ?>
    <?php if (($error ?? '') !== ''): ?>
      <div class="alert err" style="margin-bottom:16px"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (\App\Config::get('app.demo_account')): ?>
      <div class="note-box" style="margin-bottom:16px">
        <b>这是演示站，直接用演示账号进。</b>
        <div class="small" style="margin-top:6px">
          演示账号 <code>demo@fulkteam.cn</code>　密码 <code>FulkDemo2026</code>
        </div>
        <button class="btn ghost sm" type="button" style="margin-top:10px"
                onclick="document.getElementById('ident').value='demo@fulkteam.cn';document.getElementById('password').value='FulkDemo2026';this.textContent='已填入，点下面登录';this.disabled=true;">
          一键填入演示账号
        </button>
        <div class="tiny dim" style="margin-top:8px">演示数据每天 04:00 自动清空，请勿填写真实信息。</div>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= path('/login') ?>">
      <?= \App\Auth::csrfField() ?>
      <input type="hidden" name="next" value="<?= e($next ?? '') ?>">

      <div class="field">
        <label for="ident">邮箱或手机号</label>
        <input class="input" type="text" id="ident" name="ident" value="<?= e($ident ?? '') ?>"
               placeholder="you@example.com 或 13800138000" autocomplete="username" autofocus>
      </div>

      <div class="field">
        <label for="password">密码</label>
        <input class="input" type="password" id="password" name="password"
               placeholder="登录密码" autocomplete="current-password">
      </div>

      <button class="btn primary" type="submit" style="width:100%">登录</button>
    </form>

    <div class="auth-foot">
      还没账号？<a href="<?= path('/register') ?>">注册一个</a>
      <span class="dim"> · </span>
      <a href="<?= path('/forgot') ?>">忘记密码？</a>
    </div>
  </div>
</div>

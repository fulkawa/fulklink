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
      忘了密码，找客服 QQ <?= e(cfg('site.support')) ?>
    </div>
  </div>
</div>

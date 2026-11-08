<?php
use App\Auth;

/** @var array $link */
/** @var string $action */
/** @var string $error */
?>
<div class="wrap" style="max-width:460px">
  <div class="auth-wrap" style="margin:56px auto">
    <div class="auth-card">
      <div class="auth-h">这条短链需要密码</div>
      <p class="auth-sub">
        <?php if ((string) $link['title'] !== ''): ?>
          <b><?= e((string) $link['title']) ?></b><br>
        <?php endif; ?>
        发链接的人给它设了访问密码，输入后继续。
      </p>

      <?php if ($error !== ''): ?>
        <div class="alert" style="margin-bottom:14px;border-color:rgba(248,81,73,.4);color:var(--err)"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= path($action) ?>">
        <?= Auth::csrfField() ?>
        <div class="field" style="margin-bottom:14px">
          <label for="password">访问密码</label>
          <input class="input" type="password" id="password" name="password" autofocus autocomplete="off" required>
        </div>
        <button class="btn primary" type="submit" style="width:100%">继续访问</button>
      </form>

      <div class="auth-foot tiny dim">
        忘记密码了？找发给你链接的人要。
      </div>
    </div>
  </div>
</div>

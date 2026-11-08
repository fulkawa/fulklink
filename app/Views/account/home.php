<?php
/**
 * 【开源版专用】个人中心概览。账号信息 + 改密，没有别的。
 */
use App\Auth;
use App\View;

/** @var array $user */
/** @var string $notice */
/** @var string $error */
?>
<div class="wrap">
  <div class="acct">
    <?= View::partial('account/_nav', ['active' => 'home']) ?>

    <div class="acct-main">
      <?php if (($notice ?? '') !== ''): ?>
        <div class="alert" style="margin-bottom:16px;border-color:rgba(63,185,80,.35);color:var(--ok)"><?= e($notice) ?></div>
      <?php endif; ?>
      <?php if (($error ?? '') !== ''): ?>
        <div class="alert err" style="margin-bottom:16px"><?= e($error) ?></div>
      <?php endif; ?>

      <div class="acct-h">
        <h1>账号</h1>
        <a class="btn ghost" href="<?= path('/links') ?>">去工作台</a>
      </div>

      <div class="acct-card">
        <h2><?= e((string) $user['name'] !== '' ? (string) $user['name'] : (string) ($user['email'] ?? $user['phone'] ?? '我')) ?></h2>
        <dl class="kv">
          <dt>邮箱</dt><dd><?= e((string) ($user['email'] ?? '') !== '' ? (string) $user['email'] : '未绑定') ?></dd>
          <dt>手机</dt><dd><?= e((string) ($user['phone'] ?? '') !== '' ? (string) $user['phone'] : '未绑定') ?></dd>
          <dt>角色</dt><dd><?= (string) $user['role'] === 'admin' ? '管理员' : '普通用户' ?></dd>
          <dt>注册时间</dt><dd><?= e((string) $user['created_at']) ?></dd>
        </dl>
      </div>

      <div class="acct-card">
        <h2>改密码</h2>
        <p class="sub">改完会退出所有会话，需要重新登录。</p>
        <form method="post" action="<?= path('/account/password') ?>" class="form-grid">
          <?= Auth::csrfField() ?>
          <div class="field">
            <label for="op">原密码</label>
            <input class="input" type="password" id="op" name="old_password" required>
          </div>
          <div class="field">
            <label for="np">新密码</label>
            <input class="input" type="password" id="np" name="new_password" required>
          </div>
          <div class="field">
            <label for="np2">再输一次</label>
            <input class="input" type="password" id="np2" name="new_password2" required>
          </div>
          <div class="full">
            <button class="btn primary" type="submit">保存</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

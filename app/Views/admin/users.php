<?php
/**
 * 【开源版专用】后台用户管理。搜索、停用/启用、设管理员、重置密码。
 * 不能对自己操作——避免管理员把自己锁在门外。
 */
use App\Auth;
use App\View;

/** @var array $rows */
/** @var string $q */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
/** @var int $meId */
$ident = static function (array $u): string {
    return (string) ($u['email'] ?: $u['phone'] ?: ('用户#' . $u['id']));
};
?>
<div class="wrap">
  <div class="acct">
    <?= View::partial('admin/_nav', ['active' => 'users']) ?>

    <div class="acct-main">
      <?php if (($notice ?? '') !== ''): ?>
        <div class="alert" style="margin-bottom:16px;border-color:rgba(63,185,80,.35);color:var(--ok)"><?= e($notice) ?></div>
      <?php endif; ?>
      <?php if (($error ?? '') !== ''): ?>
        <div class="alert err" style="margin-bottom:16px"><?= e($error) ?></div>
      <?php endif; ?>

      <div class="acct-h">
        <h1>用户</h1>
        <span class="tiny dim">共 <?= (int) $total ?> 个账号</span>
      </div>

      <form method="get" action="<?= path('/admin/users') ?>" class="form-grid" style="margin-bottom:16px">
        <div class="field">
          <input class="input" type="text" name="q" value="<?= e($q) ?>" placeholder="搜邮箱 / 手机号 / 名字">
        </div>
        <div class="full" style="display:flex;gap:8px">
          <button class="btn" type="submit">搜索</button>
          <?php if ($q !== ''): ?><a class="btn" href="<?= path('/admin/users') ?>">清掉</a><?php endif; ?>
        </div>
      </form>

      <?php if ($rows === []): ?>
        <div class="acct-card"><p class="sub">没找到。</p></div>
      <?php else: ?>
        <div class="acct-card">
          <table class="tbl">
            <thead><tr><th>ID</th><th>账号</th><th>名字</th><th>角色</th><th>状态</th><th>短链</th><th>最后登录</th><th>注册</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $u): ?>
              <tr>
                <td class="mono"><?= (int) $u['id'] ?></td>
                <td class="mono tiny"><?= e($ident($u)) ?></td>
                <td class="tiny"><?= e((string) $u['name']) ?></td>
                <td><?= $u['role'] === 'admin' ? '<span class="tag ok">管理员</span>' : '<span class="tiny dim">用户</span>' ?></td>
                <td><?= $u['status'] === 'active' ? '<span class="tag ok">正常</span>' : '<span class="tag warn">已停用</span>' ?></td>
                <td><?= (int) $u['link_count'] ?></td>
                <td class="tiny dim"><?= e((string) ($u['last_login_at'] ?: '—')) ?></td>
                <td class="tiny dim"><?= e((string) $u['created_at']) ?></td>
                <td class="tiny">
                  <?php if ((int) $u['id'] === $meId): ?>
                    <span class="dim">自己</span>
                  <?php else: ?>
                    <form method="post" action="<?= path('/admin/users/action') ?>" style="display:inline">
                      <?= Auth::csrfField() ?>
                      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                      <?php if ($u['status'] === 'active'): ?>
                        <button class="btn tiny" name="act" value="disable" onclick="return confirm('停用后对方所有登录立即下线，确定？')">停用</button>
                      <?php else: ?>
                        <button class="btn tiny" name="act" value="enable">启用</button>
                      <?php endif; ?>
                      <?php if ($u['role'] === 'admin'): ?>
                        <button class="btn tiny" name="act" value="demote" onclick="return confirm('取消对方的管理员？')">取消管理员</button>
                      <?php else: ?>
                        <button class="btn tiny" name="act" value="promote" onclick="return confirm('给对方管理员权限？')">设管理员</button>
                      <?php endif; ?>
                      <button class="btn tiny" name="act" value="resetpass" onclick="var p=prompt('输入新密码（对方所有登录会下线）：');if(!p)return false;this.form.password.value=p;">重置密码</button>
                      <input type="hidden" name="password" value="">
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($pages > 1): ?>
          <p class="sub" style="margin-top:12px">
            第 <?= (int) $page ?> / <?= (int) $pages ?> 页
            <?php if ($page > 1): ?><a href="<?= path('/admin/users') ?>?q=<?= rawurlencode($q) ?>&page=<?= $page - 1 ?>">上一页</a><?php endif; ?>
            <?php if ($page < $pages): ?><a href="<?= path('/admin/users') ?>?q=<?= rawurlencode($q) ?>&page=<?= $page + 1 ?>">下一页</a><?php endif; ?>
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

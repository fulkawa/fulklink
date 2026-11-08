<?php
/**
 * 【开源版专用】后台短链管理。全站所有短链：搜索、停用/启用、删除。
 */
use App\Auth;
use App\Shorten;
use App\View;

/** @var array $rows */
/** @var string $q */
/** @var string $status */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
?>
<div class="wrap">
  <div class="acct">
    <?= View::partial('admin/_nav', ['active' => 'links']) ?>

    <div class="acct-main">
      <?php if (($notice ?? '') !== ''): ?>
        <div class="alert" style="margin-bottom:16px;border-color:rgba(63,185,80,.35);color:var(--ok)"><?= e($notice) ?></div>
      <?php endif; ?>
      <?php if (($error ?? '') !== ''): ?>
        <div class="alert err" style="margin-bottom:16px"><?= e($error) ?></div>
      <?php endif; ?>

      <div class="acct-h">
        <h1>短链</h1>
        <span class="tiny dim">共 <?= (int) $total ?> 条</span>
      </div>

      <form method="get" action="<?= path('/admin/links') ?>" class="form-grid" style="margin-bottom:16px">
        <div class="field">
          <input class="input" type="text" name="q" value="<?= e($q) ?>" placeholder="搜短码 / 目标 / 标题">
        </div>
        <div class="field">
          <select class="input" name="status">
            <option value="">全部状态</option>
            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>正常</option>
            <option value="disabled" <?= $status === 'disabled' ? 'selected' : '' ?>>已停用</option>
          </select>
        </div>
        <div class="full" style="display:flex;gap:8px">
          <button class="btn" type="submit">筛</button>
          <?php if ($q !== '' || $status !== ''): ?><a class="btn" href="<?= path('/admin/links') ?>">清掉</a><?php endif; ?>
        </div>
      </form>

      <?php if ($rows === []): ?>
        <div class="acct-card"><p class="sub">没找到。</p></div>
      <?php else: ?>
        <div class="acct-card">
          <table class="tbl">
            <thead><tr><th>短链</th><th>目标</th><th>归属</th><th>状态</th><th>点击</th><th>时间</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $l): ?>
              <tr>
                <td class="mono tiny"><?= e(Shorten::shortUrl($l)) ?></td>
                <td class="tiny dim"><?= e(mb_strimwidth((string) $l['target_url'], 0, 42, '…')) ?></td>
                <td class="mono tiny"><?= e((string) $l['owner']) ?></td>
                <td><?= $l['status'] === 'active' ? '<span class="tag ok">正常</span>' : '<span class="tag warn">已停用</span>' ?></td>
                <td><?= (int) $l['click_count'] ?></td>
                <td class="tiny dim"><?= e((string) $l['created_at']) ?></td>
                <td class="tiny">
                  <form method="post" action="<?= path('/admin/links/action') ?>" style="display:inline">
                    <?= Auth::csrfField() ?>
                    <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                    <?php if ($l['status'] === 'active'): ?>
                      <button class="btn tiny" name="act" value="disable" onclick="return confirm('停用后这条短链会显示已下线，确定？')">停用</button>
                    <?php else: ?>
                      <button class="btn tiny" name="act" value="enable">启用</button>
                    <?php endif; ?>
                    <button class="btn tiny" name="act" value="delete" onclick="return confirm('删除后短链和点击记录都没了，不可恢复，确定？')">删除</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if ($pages > 1): ?>
          <p class="sub" style="margin-top:12px">
            第 <?= (int) $page ?> / <?= (int) $pages ?> 页
            <?php if ($page > 1): ?><a href="<?= path('/admin/links') ?>?q=<?= rawurlencode($q) ?>&status=<?= rawurlencode($status) ?>&page=<?= $page - 1 ?>">上一页</a><?php endif; ?>
            <?php if ($page < $pages): ?><a href="<?= path('/admin/links') ?>?q=<?= rawurlencode($q) ?>&status=<?= rawurlencode($status) ?>&page=<?= $page + 1 ?>">下一页</a><?php endif; ?>
          </p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

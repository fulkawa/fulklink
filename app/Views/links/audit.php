<?php
use App\Shorten;
use App\View;

/** @var array $rows */
/** @var int $page */
/** @var int $pages */
/** @var int $total */

$actionLabel = [
    'link.create'    => '新建链接',
    'link.update'    => '修改链接',
    'link.delete'    => '删除链接',
    'link.toggle'    => '启停链接',
    'link.bulk.enable'  => '批量启用',
    'link.bulk.disable' => '批量停用',
    'link.bulk.delete'  => '批量删除',
    'link.bulk.group'   => '批量分组',
    'domain.add'     => '添加域名',
    'domain.verify'  => '验证域名',
    'domain.remove'  => '删除域名',
    'team.add'       => '加入成员',
    'team.remove'    => '移除成员',
    'api.key.create' => '生成密钥',
    'api.key.revoke' => '吊销密钥',
];
?>
<div class="wrap">
  <div class="acct">
    <?= View::partial('account/_nav', ['active' => 'audit']) ?>

    <div class="acct-main">
      <?php if (($banner['text'] ?? '') !== ''): ?>
        <div class="alert" style="margin-bottom:14px"><?= e($banner['text']) ?></div>
      <?php endif; ?>

      <div class="acct-h">
        <h1>审计日志</h1>
        <span class="tiny dim">共 <?= (int) $total ?> 条</span>
      </div>

      <div class="acct-card">
        <p class="sub">谁在什么时候动了哪条链接。企业版功能，保留全量操作记录。</p>
        <?php if ($rows === []): ?>
          <p class="small dim" style="margin:8px 0 0">还没有记录。</p>
        <?php else: ?>
          <div class="lp-table-wrap">
            <table class="tbl">
              <thead><tr><th style="width:130px">时间</th><th style="width:110px">操作人</th><th style="width:100px">动作</th><th>详情</th><th style="width:120px">IP</th></tr></thead>
              <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td class="tiny dim"><?= e(fmt_date((string) $r['created_at'], true)) ?></td>
                  <td class="small">
                    <?php
                      $who = (string) ($r['email'] ?? '');
                      if ($who === '') {
                          $phone = (string) ($r['phone'] ?? '');
                          $who = $phone !== '' ? mask_middle($phone, 3, 4) : '系统';
                      }
                    ?>
                    <?= e($who) ?>
                  </td>
                  <td class="small"><?= e($actionLabel[(string) $r['action']] ?? (string) $r['action']) ?></td>
                  <td class="small">
                    <?php if ((int) $r['link_id'] > 0): ?>
                      <a href="<?= path('/links/' . (int) $r['link_id']) ?>" style="color:var(--accent)">#<?= (int) $r['link_id'] ?></a>
                    <?php endif; ?>
                    <?= e((string) $r['detail']) ?>
                  </td>
                  <td class="mono tiny dim"><?= e((string) $r['ip']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ((int) $pages > 1): ?>
            <div class="lp-pager">
              <?php for ($i = 1; $i <= (int) $pages; $i++): ?>
                <?php if ($i === 1 || $i === (int) $pages || abs($i - (int) $page) <= 2): ?>
                  <a class="<?= $i === (int) $page ? 'on' : '' ?>" href="<?= path('/audit') ?>?page=<?= $i ?>"><?= $i ?></a>
                <?php elseif (abs($i - (int) $page) === 3): ?>
                  <span class="dim">…</span>
                <?php endif; ?>
              <?php endfor; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

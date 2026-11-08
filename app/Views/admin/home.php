<?php
/**
 * 【开源版专用】后台概览。看四个数字和最近建的链接。
 */
use App\Shorten;
use App\View;

/** @var array $counts */
/** @var array $recent */
/** @var array $events */
/** @var bool $mailOk */
/** @var bool $smsOk */
?>
<div class="wrap">
  <div class="acct">
    <?= View::partial('admin/_nav', ['active' => 'home']) ?>

    <div class="acct-main">
      <div class="acct-h">
        <h1>概览</h1>
        <span class="tiny dim">这套程序是开源版，高级功能需要商业版</span>
      </div>

      <div class="stat-row">
        <div class="stat"><div class="stat-k">用户</div><div class="stat-v"><?= (int) $counts['users'] ?></div></div>
        <div class="stat"><div class="stat-k">短链</div><div class="stat-v"><?= (int) $counts['links'] ?></div></div>
        <div class="stat"><div class="stat-k">总点击</div><div class="stat-v"><?= (int) $counts['clicks'] ?></div></div>
        <div class="stat"><div class="stat-k">今日点击</div><div class="stat-v"><?= (int) $counts['today'] ?></div></div>
      </div>

      <div class="acct-card">
        <h2>通道状态</h2>
        <dl class="kv">
          <dt>邮件 SMTP</dt><dd><?= $mailOk ? '可用' : '未配置' ?></dd>
          <dt>短信</dt><dd><?= $smsOk ? '可用' : '未配置' ?></dd>
        </dl>
        <p class="sub" style="margin-top:8px">都未配置时，注册只能用邮箱密码直接注册，验证码发不出去。</p>
      </div>

      <div class="acct-card">
        <h2>最近创建的短链</h2>
        <?php if ($recent === []): ?>
          <p class="sub">还没有。</p>
        <?php else: ?>
          <table class="tbl">
            <thead><tr><th>短链</th><th>目标</th><th>点击</th><th>时间</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $one): ?>
              <tr>
                <td class="mono"><?= e(Shorten::shortUrl($one)) ?></td>
                <td class="tiny dim"><?= e(mb_strimwidth((string) $one['target_url'], 0, 48, '…')) ?></td>
                <td><?= (int) $one['click_count'] ?></td>
                <td class="tiny dim"><?= e((string) $one['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <?php if ($events !== []): ?>
        <div class="acct-card">
          <h2>最近操作</h2>
          <table class="tbl">
            <thead><tr><th>类型</th><th>说明</th><th>时间</th></tr></thead>
            <tbody>
            <?php foreach ($events as $one): ?>
              <tr>
                <td class="mono tiny"><?= e((string) $one['kind']) ?></td>
                <td class="tiny dim"><?= e((string) $one['message']) ?></td>
                <td class="tiny dim"><?= e((string) $one['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

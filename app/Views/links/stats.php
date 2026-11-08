<?php
use App\Auth;
use App\Shorten;
use App\Ua;
use App\View;

/** @var array $link */
/** @var array $summary */
/** @var array $daily */
/** @var array $sources */
/** @var array $devices */
/** @var array $browsers */
/** @var array $regions */
/** @var array|null $log */
/** @var array $gates */
/** @var array $reasons */
/** @var int $days */

$maxDay = 1;
foreach ($daily as $d) {
    $maxDay = max($maxDay, (int) $d['n']);
}
$state = Shorten::state($link);

/** 横向条形列表：一份数据，三处复用 */
$rank = static function (array $rows, string $empty = '还没有数据'): void {
    if ($rows === []) {
        echo '<p class="small dim" style="margin:6px 0 0">' . e($empty) . '</p>';
        return;
    }
    $max = 1;
    foreach ($rows as $r) {
        $max = max($max, (int) $r['n']);
    }
    echo '<div class="lp-rank">';
    foreach ($rows as $r) {
        $w = (int) round(((int) $r['n'] / $max) * 100);
        echo '<div class="lp-rank-row">';
        echo '<span class="lp-rank-l">' . e((string) $r['label']) . '</span>';
        echo '<span class="lp-rank-b"><i style="width:' . max(2, $w) . '%"></i></span>';
        echo '<span class="lp-rank-n">' . (int) $r['n'] . '</span>';
        echo '</div>';
    }
    echo '</div>';
};

$gateBox = static function (string $reason): void {
    echo '<p class="lp-locked">' . e($reason) . ' <a href="/pricing" style="color:var(--accent)">看版本</a></p>';
};
?>
<div class="wrap">
  <div class="acct">
    <?= View::partial('account/_nav', ['active' => 'links']) ?>

    <div class="acct-main">
      <?php if (($banner['text'] ?? '') !== ''): ?>
        <div class="alert" style="margin-bottom:14px"><?= e($banner['text']) ?></div>
      <?php endif; ?>
      <?php if (($msg ?? '') !== ''): ?>
        <div class="alert" style="margin-bottom:14px;border-color:rgba(63,185,80,.35);color:var(--ok)"><?= e($msg) ?></div>
      <?php endif; ?>

      <div class="acct-h">
        <h1><?= e((string) $link['slug']) ?></h1>
        <div class="lp-head-act">
          <a class="btn ghost" href="<?= path('/links') ?>">回列表</a>
          <a class="btn ghost" href="<?= path('/links/' . (int) $link['id'] . '/qr') ?>">二维码</a>
          <a class="btn primary" href="<?= path('/links/' . (int) $link['id'] . '/edit') ?>">编辑</a>
        </div>
      </div>

      <div class="acct-card">
        <div class="lic-top">
          <span class="lic-key"><?= e(Shorten::rootUrl($link)) ?></span>
          <span class="tag <?= $state === 'active' ? 'ok' : 'warn' ?>"><?= e(Shorten::stateLabel($state)) ?></span>
        </div>
        <div class="lic-meta">
          <span class="lp-ellipsis" style="max-width:100%">→ <?= e((string) $link['target_url']) ?></span>
          <?php if ((string) $link['password_hash'] !== ''): ?><span>有访问密码</span><?php endif; ?>
          <?php if (($link['expires_at'] ?? null) !== null): ?><span>到期 <?= e(fmt_date((string) $link['expires_at'], true)) ?></span><?php endif; ?>
          <?php if ((int) $link['max_clicks'] > 0): ?><span>上限 <?= (int) $link['max_clicks'] ?> 次</span><?php endif; ?>
        </div>
        <button type="button" class="lp-copy" id="copy-btn" data-clip="<?= e(Shorten::rootUrl($link)) ?>">复制短链</button>
      </div>

      <div class="stat-row">
        <div class="stat"><div class="stat-n"><?= (int) $summary['total'] ?></div><div class="stat-l">总点击</div></div>
        <div class="stat"><div class="stat-n"><?= (int) $summary['uniq'] ?></div><div class="stat-l">独立访客</div></div>
        <div class="stat"><div class="stat-n"><?= (int) $summary['today'] ?></div><div class="stat-l">今日</div></div>
        <div class="stat"><div class="stat-n"><?= (int) $summary['last7'] ?></div><div class="stat-l">近 7 天</div></div>
      </div>

      <?php if ((int) $summary['total'] === 0): ?>
        <div class="note-box">
          <b>还没有点击。</b>短链已经能用了，把它发出去就会开始记录。
          统计只算真人，爬虫不计数。
        </div>
      <?php endif; ?>

      <div class="acct-card">
        <div class="lp-card-head">
          <h2 style="margin:0">按天</h2>
          <div class="lp-days">
            <?php foreach ([7, 14, 30, 90] as $d): ?>
              <a class="<?= (int) $days === $d ? 'on' : '' ?>" href="<?= path('/links/' . (int) $link['id']) ?>?days=<?= $d ?>"><?= $d ?> 天</a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if (!$gates['daily']): ?>
          <?php $gateBox($reasons['daily']); ?>
        <?php elseif ($daily === []): ?>
          <p class="small dim" style="margin:8px 0 0">还没有数据。</p>
        <?php else: ?>
          <div class="lp-trend lp-trend-lg">
            <?php foreach ($daily as $d): ?>
              <?php $h = (int) round(((int) $d['n'] / $maxDay) * 100); ?>
              <div class="lp-bar" title="<?= e($d['day']) ?>：<?= (int) $d['n'] ?> 次点击，<?= (int) $d['u'] ?> 个访客">
                <div class="lp-bar-fill" style="height:<?= max(2, $h) ?>%"></div>
                <span class="lp-bar-n"><?= (int) $d['n'] ?></span>
                <span class="lp-bar-x"><?= e($d['label']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="lp-cols">
        <div class="acct-card">
          <h2>来源</h2>
          <p class="sub">访客从哪儿点进来的</p>
          <?php if (!$gates['source']): ?>
            <?php $gateBox($reasons['source']); ?>
          <?php else: ?>
            <?php $rank($sources); ?>
          <?php endif; ?>
        </div>

        <div class="acct-card">
          <h2>设备</h2>
          <p class="sub">手机还是电脑</p>
          <?php if (!$gates['device']): ?>
            <?php $gateBox($reasons['device']); ?>
          <?php else: ?>
            <?php $rank($devices); ?>
            <?php if ($browsers !== []): ?>
              <div class="lp-sub-h">浏览器</div>
              <?php $rank($browsers); ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="acct-card">
        <h2>地区</h2>
        <p class="sub">按 IP 归属地统计，本地库没覆盖的会显示为空</p>
        <?php if (!$gates['geo']): ?>
          <?php $gateBox($reasons['geo']); ?>
        <?php else: ?>
          <?php $rank($regions, '还没有归属地数据。云端 IP 库同步后新点击会有值。'); ?>
        <?php endif; ?>
      </div>

      <div class="acct-card">
        <div class="lp-card-head">
          <h2 style="margin:0">逐次记录</h2>
          <?php if ($gates['export']): ?>
            <a class="btn ghost" href="<?= path('/links/export') ?>?link=<?= (int) $link['id'] ?>">导出 CSV</a>
          <?php endif; ?>
        </div>
        <?php if (!$gates['log']): ?>
          <?php $gateBox($reasons['log']); ?>
        <?php elseif ($log === null || $log['rows'] === []): ?>
          <p class="small dim" style="margin:8px 0 0">还没有记录。</p>
        <?php else: ?>
          <div class="lp-table-wrap">
            <table class="tbl">
              <thead><tr><th>时间</th><th>来源</th><th>设备</th><th>系统</th><th>浏览器</th><th>地区</th></tr></thead>
              <tbody>
              <?php foreach ($log['rows'] as $r): ?>
                <tr>
                  <td class="tiny dim"><?= e(fmt_date((string) $r['ts'], true)) ?></td>
                  <td class="small"><?= e(Ua::sourceLabel((string) ($r['source'] !== '' ? $r['source'] : 'direct'))) ?></td>
                  <td class="small"><?= e(Ua::deviceLabel((string) $r['device'])) ?></td>
                  <td class="small"><?= e((string) $r['os']) ?></td>
                  <td class="small"><?= e((string) $r['browser']) ?></td>
                  <td class="small"><?= e((string) ($r['region'] !== '' ? $r['region'] : '—')) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if ((int) $log['pages'] > 1): ?>
            <div class="lp-pager">
              <?php for ($i = 1; $i <= (int) $log['pages']; $i++): ?>
                <?php if ($i <= 2 || $i > (int) $log['pages'] - 2 || abs($i - (int) $log['page']) <= 1): ?>
                  <a class="<?= $i === (int) $log['page'] ? 'on' : '' ?>" href="<?= path('/links/' . (int) $link['id']) ?>?days=<?= (int) $days ?>&page=<?= $i ?>"><?= $i ?></a>
                <?php endif; ?>
              <?php endfor; ?>
              <span class="tiny dim" style="margin-left:8px">共 <?= (int) $log['total'] ?> 条</span>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.lp-copy').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var text = btn.getAttribute('data-clip') || '';
    var done = function () {
      var old = btn.textContent;
      btn.textContent = '已复制';
      setTimeout(function () { btn.textContent = old; }, 1200);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () {});
    } else {
      var ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (e) {}
      document.body.removeChild(ta);
    }
  });
});
</script>

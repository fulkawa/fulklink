<?php
use App\Auth;
use App\Shorten;
use App\View;

/** @var array $user */
/** @var array $list */
/** @var array $filters */
/** @var array $groups */
/** @var array $domains */
/** @var array $overview */
/** @var array $trend */
/** @var array $top */
/** @var array $used */
/** @var array $limit */
/** @var bool $isOwner */
/** @var string $query */
/** @var array $banner */

$maxTrend = 1;
foreach ($trend as $d) {
    $maxTrend = max($maxTrend, (int) $d['n']);
}
$tagClass = ['active' => 'ok', 'disabled' => 'warn', 'expired' => 'err', 'capped' => 'err'];
$perPage = 20;
$base = '/links?';
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
      <?php if (($err ?? '') !== ''): ?>
        <div class="alert" style="margin-bottom:14px;border-color:rgba(248,81,73,.4);color:var(--err)"><?= e($err) ?></div>
      <?php endif; ?>

      <div class="acct-h">
        <h1>短链</h1>
        <div class="lp-head-act">
          <a class="btn primary" href="<?= path('/links/new') ?>">新建短链</a>
        </div>
      </div>

      <div class="stat-row">
        <div class="stat"><div class="stat-n"><?= (int) $overview['links'] ?></div><div class="stat-l">全部链接</div></div>
        <div class="stat"><div class="stat-n"><?= (int) $overview['active'] ?></div><div class="stat-l">正常中</div></div>
        <div class="stat"><div class="stat-n"><?= (int) $overview['clicks'] ?></div><div class="stat-l">累计点击</div></div>
        <div class="stat"><div class="stat-n"><?= (int) $overview['today'] ?></div><div class="stat-l">今日点击</div></div>
      </div>

      <div class="acct-card">
        <div class="lp-card-head">
          <h2 style="margin:0">近 14 天点击</h2>
          <span class="tiny dim">近 7 天 <?= (int) $overview['last7'] ?> 次</span>
        </div>
        <?php if ((int) $overview['clicks'] === 0): ?>
          <p class="small dim" style="margin:10px 0 0">还没有点击。把短链发出去，这里就会有数据。</p>
        <?php else: ?>
          <div class="lp-trend">
            <?php foreach ($trend as $d): ?>
              <?php $h = (int) round(((int) $d['n'] / $maxTrend) * 100); ?>
              <div class="lp-bar" title="<?= e($d['day']) ?> 点击 <?= (int) $d['n'] ?>">
                <div class="lp-bar-fill" style="height:<?= max(2, $h) ?>%"></div>
                <span class="lp-bar-n"><?= (int) $d['n'] ?></span>
                <span class="lp-bar-x"><?= e($d['label']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php
        $linkLimit = (int) $limit['links'];
        $nearLimit = $linkLimit > 0 && (int) $used['links'] >= $linkLimit * 0.9;
      ?>
      <?php if ($linkLimit > 0): ?>
        <p class="tiny <?= $nearLimit ? '' : 'dim' ?>" style="margin:0 0 10px">
          已用 <?= (int) $used['links'] ?> / <?= $linkLimit ?> 条<?= $nearLimit ? '，快到上限了' : '' ?>。
          <?php if ($nearLimit): ?><a href="<?= path('/pricing') ?>" style="color:var(--accent)">看升级</a><?php endif; ?>
        </p>
      <?php endif; ?>

      <div class="acct-card">
        <form method="get" action="<?= path('/links') ?>" class="lp-toolbar">
          <input class="input" type="text" name="q" value="<?= e($filters['q']) ?>" placeholder="搜后缀、标题、目标地址" style="flex:1;min-width:160px">
          <select class="input" name="status">
            <option value="">全部状态</option>
            <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>正常</option>
            <option value="disabled" <?= $filters['status'] === 'disabled' ? 'selected' : '' ?>>已停用</option>
          </select>
          <?php if ($groups !== []): ?>
            <select class="input" name="group">
              <option value="">全部分组</option>
              <option value="__none" <?= $filters['group'] === '__none' ? 'selected' : '' ?>>未分组</option>
              <?php foreach ($groups as $g): ?>
                <option value="<?= e($g['name']) ?>" <?= $filters['group'] === $g['name'] ? 'selected' : '' ?>><?= e($g['name']) ?>（<?= (int) $g['n'] ?>）</option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <?php if (count($domains) > 0): ?>
            <select class="input" name="domain">
              <option value="">全部域名</option>
              <option value="<?= e(Shorten::defaultHost()) ?>" <?= $filters['domain'] === Shorten::defaultHost() ? 'selected' : '' ?>>主域名</option>
              <?php foreach ($domains as $d): ?>
                <option value="<?= e($d['host']) ?>" <?= $filters['domain'] === $d['host'] ? 'selected' : '' ?>><?= e($d['host']) ?></option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
          <select class="input" name="sort">
            <option value="">最新在前</option>
            <option value="clicks" <?= $filters['sort'] === 'clicks' ? 'selected' : '' ?>>点击最多</option>
          </select>
          <button class="btn ghost" type="submit">筛选</button>
          <?php if (array_filter($filters, static fn ($v) => (string) $v !== '') !== []): ?>
            <a class="btn ghost" href="<?= path('/links') ?>">清空</a>
          <?php endif; ?>
        </form>

        <?php if ($list['rows'] === []): ?>
          <div style="padding:40px 0;text-align:center">
            <p class="dim" style="margin:0 0 14px">
              <?= array_filter($filters, static fn ($v) => (string) $v !== '') === [] ? '还没建过短链。' : '没有符合条件的链接。' ?>
            </p>
            <a class="btn primary" href="<?= path('/links/new') ?>">建第一条</a>
          </div>
        <?php else: ?>
          <form method="post" action="<?= path('/links/bulk') ?>" id="bulk-form">
            <?= Auth::csrfField() ?>
            <div class="lp-bulk">
              <?php if (\App\Cloud\Gate::allows('links.bulk')): ?>
                <select class="input" name="action" style="width:auto">
                  <option value="disable">批量停用</option>
                  <option value="enable">批量启用</option>
                  <option value="group">移到分组…</option>
                  <option value="delete">批量删除</option>
                </select>
                <input class="input" type="text" name="group" placeholder="分组名（选「移到分组」时填）" style="width:180px">
                <button class="btn ghost" type="submit" onclick="return confirm('对选中的链接执行这个操作？')">执行</button>
              <?php else: ?>
                <span class="tiny dim">批量操作需要专业版</span>
              <?php endif; ?>
              <?php if (\App\Cloud\Gate::allows('stats.export')): ?>
                <a class="btn ghost" style="margin-left:auto" href="<?= path('/links/export') ?>">导出点击 CSV</a>
              <?php endif; ?>
            </div>

            <div class="lp-table-wrap">
              <table class="tbl lp-tbl">
                <thead>
                  <tr>
                    <th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.js-pick').forEach(c=>c.checked=this.checked)"></th>
                    <th>短链</th>
                    <th>目标</th>
                    <th style="width:76px">状态</th>
                    <th style="width:72px">点击</th>
                    <th style="width:96px">创建</th>
                    <th style="width:150px"></th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($list['rows'] as $row): ?>
                  <?php $st = (string) $row['state']; ?>
                  <tr>
                    <td><input class="js-pick" type="checkbox" name="ids[]" value="<?= (int) $row['id'] ?>"></td>
                    <td>
                      <div class="lp-slug">
                        <a href="<?= path('/links/' . $row['id']) ?>"><?= e((string) $row['slug']) ?></a>
                        <button type="button" class="lp-copy" data-clip="<?= e(Shorten::rootUrl($row)) ?>" title="复制短链">复制</button>
                      </div>
                      <div class="tiny dim lp-url"><?= e(Shorten::rootUrl($row)) ?></div>
                    </td>
                    <td class="lp-target">
                      <?php if ((string) $row['title'] !== ''): ?>
                        <div class="lp-title"><?= e((string) $row['title']) ?></div>
                      <?php endif; ?>
                      <div class="tiny dim lp-ellipsis" title="<?= e((string) $row['target_url']) ?>"><?= e((string) $row['target_url']) ?></div>
                      <?php if ((string) ($row['group_name'] ?? '') !== ''): ?>
                        <span class="tag" style="margin-top:4px"><?= e((string) $row['group_name']) ?></span>
                      <?php endif; ?>
                      <?php if ((string) $row['password_hash'] !== ''): ?>
                        <span class="tag warn" style="margin-top:2px">有密码</span>
                      <?php endif; ?>
                    </td>
                    <td><span class="tag <?= $tagClass[$st] ?? '' ?>"><?= e(Shorten::stateLabel($st)) ?></span></td>
                    <td><b><?= (int) $row['click_count'] ?></b></td>
                    <td class="tiny dim"><?= e(fmt_date((string) $row['created_at'])) ?></td>
                    <td class="tiny lp-ops">
                      <a href="<?= path('/links/' . $row['id']) ?>">统计</a>
                      <a href="<?= path('/links/' . $row['id'] . '/edit') ?>">编辑</a>
                      <a href="<?= path('/links/' . $row['id'] . '/qr') ?>">二维码</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </form>

          <?php if ((int) $list['pages'] > 1): ?>
            <div class="lp-pager">
              <?php for ($i = 1; $i <= (int) $list['pages']; $i++): ?>
                <?php if ($i === 1 || $i === (int) $list['pages'] || abs($i - (int) $list['page']) <= 2): ?>
                  <a class="<?= $i === (int) $list['page'] ? 'on' : '' ?>" href="<?= path('/links') ?>?page=<?= $i ?><?= e($query) ?>"><?= $i ?></a>
                <?php elseif (abs($i - (int) $list['page']) === 3): ?>
                  <span class="dim">…</span>
                <?php endif; ?>
              <?php endfor; ?>
              <span class="tiny dim" style="margin-left:8px">共 <?= (int) $list['total'] ?> 条</span>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php if ($top !== []): ?>
        <div class="acct-card">
          <h2>点击最多的 5 条</h2>
          <table class="tbl">
            <thead><tr><th>短链</th><th>标题</th><th style="width:80px">点击</th></tr></thead>
            <tbody>
            <?php foreach ($top as $t): ?>
              <tr>
                <td><a href="<?= path('/links/' . $t['id']) ?>" style="color:var(--accent)"><?= e((string) $t['slug']) ?></a></td>
                <td class="small"><?= e((string) ($t['title'] !== '' ? $t['title'] : '—')) ?></td>
                <td><b><?= (int) $t['click_count'] ?></b></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
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
      navigator.clipboard.writeText(text).then(done, function () { fallback(text, done); });
    } else {
      fallback(text, done);
    }
  });
});
function fallback(text, done) {
  var ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  try { document.execCommand('copy'); done(); } catch (e) {}
  document.body.removeChild(ta);
}
</script>

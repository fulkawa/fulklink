<?php
use App\Auth;
use App\Domains;
use App\View;

/** @var array $domains */
/** @var string $primary */
/** @var string $siteUrl */
/** @var array $used */
/** @var int $limit */
/** @var bool $canAdd */
/** @var bool $unlimited */
/** @var string $reason */

$limitText = $limit > 0 ? ('已用 ' . (int) $used['domains'] . ' / ' . $limit . ' 个') : '不限个数';
$hint = Domains::accessHint('', $siteUrl);
$serverIp = $hint['a']['value'];
$siteHost = $hint['cname']['value'];
?>
<div class="wrap">
  <div class="acct">
    <?= View::partial('account/_nav', ['active' => 'domains']) ?>

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
        <h1>域名</h1>
        <span class="tiny dim"><?= e($limitText) ?></span>
      </div>

      <div class="acct-card">
        <h2>主域名</h2>
        <p class="sub">不改也行，所有短链默认用这个域名。</p>
        <div class="key-value">
          <span class="key-box"><?= e($primary) ?></span>
        </div>
        <p class="tiny dim" style="margin:10px 0 0">
          短链格式：<code class="code">https://<?= e($primary) ?>/r/后缀</code>
        </p>
      </div>

      <?php if (!$canAdd): ?>
        <div class="note-box">
          <b>自定义域名需要个人版以上。</b><?= e($reason) ?>
          <a href="<?= path('/pricing') ?>" style="color:var(--accent)">看版本 →</a>
        </div>
      <?php else: ?>
        <div class="acct-card">
          <h2>加一个自己的域名</h2>
          <p class="sub">用你自己的域名做短链，发出去更像自己的品牌。</p>
          <form method="post" action="<?= path('/domains/new') ?>" class="lp-toolbar">
            <?= Auth::csrfField() ?>
            <input class="input" type="text" name="host" placeholder="link.example.com" style="flex:1;min-width:200px" required>
            <button class="btn primary" type="submit">添加</button>
          </form>
          <p class="tiny dim" style="margin:10px 0 0">
            只填域名，不带 <code class="code">https://</code> 和路径。加完要验证归属，验证通过才能用来建链接。
          </p>
        </div>
      <?php endif; ?>

      <div class="acct-card">
        <h2>已添加的域名</h2>
        <?php if ($domains === []): ?>
          <p class="small dim" style="margin:8px 0 0">还没有添加。不加也能用，短链会挂在主域名下。</p>
        <?php else: ?>
          <?php foreach ($domains as $d): ?>
            <?php $ok = (int) $d['verified'] === 1; ?>
            <div class="dom-item">
              <div class="dom-list">
                <div>
                  <b><?= e((string) $d['host']) ?></b>
                  <span class="tag <?= $ok ? 'ok' : 'warn' ?>" style="margin-left:8px"><?= $ok ? '已验证' : '待验证' ?></span>
                  <div class="tiny dim" style="margin-top:4px">
                    <?= (int) $d['links'] ?> 条链接在用
                    <?php if (!$ok && (string) ($d['last_check_at'] ?? '') !== ''): ?>
                      · 上次检查 <?= e(fmt_date((string) $d['last_check_at'], true)) ?>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="lp-ops">
                  <?php if (!$ok): ?>
                    <form method="post" action="<?= path('/domains/' . (int) $d['id'] . '/verify') ?>" style="display:inline">
                      <?= Auth::csrfField() ?>
                      <button class="btn ghost" type="submit">验证</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="<?= path('/domains/' . (int) $d['id'] . '/delete') ?>" style="display:inline"
                        onsubmit="return confirm('确定删除 <?= e((string) $d['host']) ?>？')">
                    <?= Auth::csrfField() ?>
                    <button class="btn ghost" type="submit">删除</button>
                  </form>
                </div>
              </div>

              <?php if (!$ok): ?>
                <div class="dom-box">
                  <div class="tiny" style="margin-bottom:6px"><b>第 1 步：加一条 TXT 记录，证明域名是你的</b></div>
                  <table class="tbl" style="margin-bottom:10px">
                    <thead><tr><th style="width:120px">类型</th><th>主机记录</th><th>记录值</th></tr></thead>
                    <tbody>
                      <tr>
                        <td>TXT</td>
                        <td class="mono tiny"><?= e(Domains::txName((string) $d['host'])) ?></td>
                        <td class="mono tiny"><?= e(Domains::txValue($d)) ?></td>
                      </tr>
                    </tbody>
                  </table>
                  <div class="tiny" style="margin-bottom:6px"><b>第 2 步：让这个域名指向本站</b></div>
                  <table class="tbl" style="margin-bottom:10px">
                    <thead><tr><th style="width:120px">类型</th><th>主机记录</th><th>记录值</th></tr></thead>
                    <tbody>
                      <tr>
                        <td>A</td>
                        <td class="mono tiny"><?= e((string) $d['host']) ?></td>
                        <td class="mono tiny"><?= e($serverIp) ?></td>
                      </tr>
                      <tr>
                        <td>CNAME</td>
                        <td class="mono tiny"><?= e((string) $d['host']) ?></td>
                        <td class="mono tiny"><?= e($siteHost) ?></td>
                      </tr>
                    </tbody>
                  </table>
                  <p class="tiny dim" style="margin:0">
                    A 和 CNAME 二选一。解析生效后，还要在你的服务器（宝塔：网站 → 设置 → 域名管理）里把这个域名绑到本站，
                    并给它签证书。这两步不做，短链会打不开——程序这边只能管到数据。
                  </p>
                </div>
              <?php else: ?>
                <div class="dom-box">
                  <p class="tiny dim" style="margin:0">
                    已经验证通过。建链接时在「使用的域名」里选它，短地址就是
                    <code class="code">https://<?= e((string) $d['host']) ?>/后缀</code>。
                    如果打不开，检查服务器上有没有绑这个域名、证书有没有签。
                  </p>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="acct-card">
        <h2>为什么不自动生效</h2>
        <p class="sub">说清楚省得你以为程序没做好。</p>
        <div class="doc-body">
          <p>本站是装在你自己的服务器上的，域名解析指向哪儿由你决定，程序没法替你改 DNS，也没法替你的服务器签证书。所以这里只做两件事：</p>
          <ul>
            <li><b>登记与验证</b>：确认这个域名确实是你的（TXT 记录），防止别人把别人的域名加成自己的短链出口。</li>
            <li><b>生成短地址</b>：验证通过后，建链接时就能选这个域名，短链变成 <code class="code">https://你的域名/后缀</code>。</li>
          </ul>
          <p>解析和证书配好之后，短链立刻可用，不用重启也不用重新部署。</p>
        </div>
      </div>
    </div>
  </div>
</div>

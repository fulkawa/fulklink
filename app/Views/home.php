<?php
/** @var array $plans */
$plans = \App\Plans::publicList();
?>

<section class="hero">
  <div class="wrap">
    <div class="hero-kicker">PHP + MySQL · 源码交付</div>
    <h1>短链、二维码、跳转记录</h1>
    <p class="lead">
      一套装在自己服务器上的程序。买的是源码包和域名授权，装在哪儿、数据存哪里、谁能看，都由你决定。
      各版本的演示站分开部署，能真的建短链、看统计，觉得合用再买。
    </p>
    <div class="hero-act">
      <a class="btn primary lg" href="<?= path('/pricing') ?>">看价格和版本</a>
      <a class="btn ghost lg" href="<?= path('/docs') ?>">安装文档</a>
    </div>
    <div class="hero-note tiny dim">
      运行环境：PHP 7.4 / 8.x + MySQL 5.7 以上，一个能绑域名的服务器即可。不需要 Composer，不需要 Node。
    </div>
  </div>
</section>

<section class="wrap sect">
  <h2>能做什么</h2>
  <p class="sub">就三件事，做到能查、能改、能导出。</p>
  <div class="cards3">
    <div class="card">
      <div class="card-t">短链</div>
      <p>长地址压成短码，支持自定义后缀、单独设密码、设有效期和点击上限。到期或停用后立刻不再跳转。</p>
    </div>
    <div class="card">
      <div class="card-t">二维码</div>
      <p>每条短链都能出二维码，PNG 直接下载，可调尺寸。印刷物料上用的就是它。</p>
    </div>
    <div class="card">
      <div class="card-t">跳转记录</div>
      <p>每一次点击单独存一行，不是只给你一个总数。投诉来的时候能翻到具体时间和来源。</p>
    </div>
  </div>
</section>

<section class="wrap sect">
  <h2>点击记录里到底存了什么</h2>
  <p class="sub">下面是 <code>clicks</code> 表的实际字段，不是宣传词。</p>
  <div class="panel">
    <table class="tbl">
      <thead>
        <tr><th style="width:130px">字段</th><th>存的什么</th></tr>
      </thead>
      <tbody>
        <tr><td class="mono">ts / day</td><td>点击时间（精确到秒）与日期，按日期建了索引，查某天很快</td></tr>
        <tr><td class="mono">ip_hash</td><td>IP 的 SHA-256 摘要，不存明文 IP，能判重但拿不到具体地址</td></tr>
        <tr><td class="mono">ua</td><td>浏览器原始 User-Agent</td></tr>
        <tr><td class="mono">referer</td><td>从哪个页面跳过来的</td></tr>
        <tr><td class="mono">device / os / browser</td><td>从 UA 解出来的设备类型、系统、浏览器</td></tr>
        <tr><td class="mono">region</td><td>地域，需要你自己接 IP 库才会有值，默认是空的</td></tr>
      </tbody>
    </table>
  </div>
</section>

<section class="wrap sect">
  <h2>交付方式</h2>
  <div class="cols2">
    <div>
      <p><strong>你拿到的</strong>：一个压缩包，解压后上传到站点根目录，跑一遍安装向导填数据库信息，就能用。授权码在下单结果页和邮件里，装好后绑到你的域名上。</p>
      <p><strong>你需要会</strong>：建一个 PHP 站点、建一个 MySQL 库、绑域名、配 SSL。宝塔面板点几下就行，不用懂代码。</p>
      <p><strong>我们不做的</strong>：不托管你的数据，不提供 SaaS 版，也不接管你的服务器。装不上可以问，服务器本身的环境问题需要你自己处理。</p>
    </div>
    <div class="panel panel-pad">
      <div class="panel-t">授权怎么算</div>
      <ul class="ticks">
        <li>一张授权码绑一个主域名，子域名自动放行</li>
        <li>订阅版到期前续费，沿用同一张码，域名不用重绑</li>
        <li>买断版永久可用，不含后续大版本</li>
        <li>换域名找客服改绑，24 小时内限一次</li>
      </ul>
    </div>
  </div>
</section>

<section class="wrap sect">
  <h2>版本</h2>
  <p class="sub">功能是叠加的，不是拆开卖的。</p>
  <div class="plans">
    <?php foreach ($plans as $plan): ?>
      <div class="plan<?= $plan['code'] === 'pro' ? ' plan-hot' : '' ?>">
        <div class="plan-name"><?= e($plan['name']) ?><?= $plan['suffix'] !== '' ? ' · ' . e($plan['suffix']) : '' ?></div>
        <div class="plan-price">
          <?= e(\App\Plans::price($plan['price'])) ?><span class="plan-unit"><?= e(\App\Plans::priceUnit($plan)) ?></span>
        </div>
        <div class="plan-tag"><?= e($plan['tag']) ?></div>
        <p class="plan-desc"><?= e($plan['desc']) ?></p>
        <a class="btn <?= $plan['code'] === 'pro' ? 'primary' : 'ghost' ?> block" href="<?= path('/pricing') ?>">看详情</a>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="wrap sect sect-cta">
  <div class="cta-box">
    <div>
      <div class="cta-t">先看清楚，再决定买不买</div>
      <p class="small dim">价格、权益、能做什么、装的时候会遇到什么，站上都写全了。看完还有问题，直接问客服。</p>
    </div>
    <a class="btn primary lg" href="<?= path('/pricing') ?>">选个版本</a>
  </div>
</section>

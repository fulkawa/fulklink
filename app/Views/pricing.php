<?php
/** @var array $plans */
// 定价页只展示付费档；开源版单独在文档里说明
$plans = \App\Plans::publicList();
$groups = [
    ['key' => 'personal', 'name' => '个人私用版', 'note' => '月付 29 / 年付 299 / 买断 699', 'demo' => 'https://personal.fulkteam.cn'],
    ['key' => 'pro', 'name' => '专业私用版', 'note' => '1,980 / 年', 'demo' => 'https://pro.fulkteam.cn'],
    ['key' => 'enterprise', 'name' => '企业私用版', 'note' => '6,800 / 年', 'demo' => 'https://enterprise.fulkteam.cn'],
];
/* 演示链接只在官网显示 */
$isOfficial = str_contains((string) cfg('app.url'), 'lp.fulkteam.cn');
$rows = [
    ['短链与二维码', '有', '有', '有'],
    ['按天 / 来源 / 设备统计', '有', '有', '有'],
    ['逐次跳转记录', '有', '有', '有'],
    ['链接密码与有效期', '有', '有', '有'],
    ['自定义域名', '1 个', '3 个', '不限'],
    ['成员账号', '1 个', '5 个', '不限'],
    ['数据导出 CSV', '—', '有', '有'],
    ['审计日志', '—', '—', '有'],
    ['目标域名白名单', '—', '—', '有'],
    ['开放 API', '—', '—', '有'],
];
?>

<section class="poster-head">
  <div class="wrap">
    <h1>价格</h1>
    <p class="lead">
      按版本卖的是同一套源码，区别在额度、域名数和进阶功能。订阅版到期续费沿用同一张授权码，域名不用重绑。
    </p>
  </div>
</section>

<section class="wrap sect">
  <?php
  // 私用版 / 商业版两个系列分别展示（commercial SKU 按年付，含对外落地页能力）
  $private = array_values(array_filter($plans, static fn ($p) => !str_ends_with($p['code'], '_commercial')));
  $commercial = array_values(array_filter($plans, static fn ($p) => str_ends_with($p['code'], '_commercial')));
  ?>
  <h2>私用版 · 只在自己团队内部用</h2>
  <p class="sub">不对外开放注册，打开就是工作台。按档位买，够用就好。演示站打开即登录（私用版真实形态），演示账号见下。</p>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
    <?php
    $innerDemos = ['personal' => 'https://inner-personal.fulkteam.cn', 'pro' => 'https://inner-pro.fulkteam.cn', 'enterprise' => 'https://inner-enterprise.fulkteam.cn'];
    foreach ($private as $plan): ?>
      <div class="plan<?= $plan['code'] === 'pro' ? ' plan-hot' : '' ?>">
        <div class="plan-name"><?= e($plan['name']) ?><?= $plan['suffix'] !== '' ? ' · ' . e($plan['suffix']) : '' ?></div>
        <div class="plan-price">
          <?= e(\App\Plans::price($plan['price'])) ?><span class="plan-unit"><?= e(\App\Plans::priceUnit($plan)) ?></span>
        </div>
        <div class="plan-tag"><?= e($plan['tag']) ?></div>
        <p class="plan-desc"><?= e($plan['desc']) ?></p>
        <?php if ($isOfficial && isset($innerDemos[$plan['code']])): ?>
          <a class="btn ghost block" style="margin-bottom:8px" href="<?= e($innerDemos[$plan['code']]) ?>" target="_blank" rel="noopener noreferrer">看内部演示 ↗</a>
        <?php endif; ?>
        <a class="btn <?= $plan['code'] === 'pro' ? 'primary' : 'ghost' ?> block" href="<?= path('/buy') ?>?plan=<?= e($plan['code']) ?>">买这个</a>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="tiny dim" style="margin-top:10px">
    私用版演示账号：demo@fulkteam.cn / 密码 FulkDemo2026（三个私用演示站通用）。
    演示站数据每天 04:00 重置。
  </p>
  <h2 style="margin-top:48px">商业版 · 拿它发展自己的客户</h2>
  <p class="sub">在对应私用版全部能力之上，解锁对外落地页：你的访客打开你的域名，先看到产品门面，注册进来用。客户是你的，收入也是你的。</p>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
    <?php foreach ($commercial as $plan): ?>
      <div class="plan<?= $plan['code'] === 'enterprise_commercial' ? ' plan-hot' : '' ?>">
        <div class="plan-name"><?= e($plan['name']) ?><?= $plan['suffix'] !== '' ? ' · ' . e($plan['suffix']) : '' ?></div>
        <div class="plan-price">
          <?= e(\App\Plans::price($plan['price'])) ?><span class="plan-unit"><?= e(\App\Plans::priceUnit($plan)) ?></span>
        </div>
        <div class="plan-tag"><?= e($plan['tag']) ?></div>
        <p class="plan-desc"><?= e($plan['desc']) ?></p>
        <a class="btn <?= $plan['code'] === 'enterprise_commercial' ? 'primary' : 'ghost' ?> block" href="<?= path('/buy') ?>?plan=<?= e($plan['code']) ?>">买这个</a>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="tiny dim" style="margin-top:16px">
    开源版不在上表：源码公开、免费，功能有硬限制，见
    <a href="<?= path('/docs') ?>" style="color:var(--accent)">文档</a>。
    月付个人私用版同样可选（¥29/月）。
  </p>
  <p class="small" style="margin-top:10px">
    先试再买，四档都有独立演示站：
    <a href="https://free.fulkteam.cn" target="_blank" rel="noopener noreferrer" style="color:var(--accent)">开源免费版</a> ·
    <a href="https://personal.fulkteam.cn" target="_blank" rel="noopener noreferrer" style="color:var(--accent)">个人商业版</a> ·
    <a href="https://pro.fulkteam.cn" target="_blank" rel="noopener noreferrer" style="color:var(--accent)">专业商业版</a> ·
    <a href="https://enterprise.fulkteam.cn" target="_blank" rel="noopener noreferrer" style="color:var(--accent)">企业商业版</a>
    <span class="tiny dim">（演示账号见首页，数据每天 04:00 重置）</span>
  </p>
</section>

<section class="wrap sect">
  <h2>权益对照</h2>
  <p class="sub">左边第一列是功能，右边三列是私用版档位。商业版 = 对应私用版全部权益 + 对外落地页（访客可注册）。</p>
  <div class="panel">
    <table class="tbl">
      <thead>
        <tr>
          <th style="width:38%">功能</th>
          <?php foreach ($groups as $g): ?>
            <th><?= e($g['name']) ?><div class="tiny dim" style="font-weight:400;margin-top:2px"><?= e($g['note']) ?></div><?php if ($isOfficial): ?><div style="font-weight:400;margin-top:4px"><a href="<?= e($g['demo']) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--accent)">看演示 ↗</a></div><?php endif; ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><?= e($row[0]) ?></td>
            <?php for ($i = 1; $i <= 3; $i++): ?>
              <td class="<?= $row[$i] === '—' ? 'dim' : '' ?>"><?= e($row[$i]) ?></td>
            <?php endfor; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="wrap sect">
  <h2>付款和交付</h2>
  <div class="cols2">
    <div>
      <p><strong>付款</strong>：选好版本填个邮箱就能付，不用注册。微信扫码或支付宝，付完几秒内自动发码。</p>
      <p><strong>交付</strong>：授权码显示在付款结果页，同时发到你填的邮箱，源码包和安装说明也在里面。</p>
      <p><strong>对公转账</strong>：备注订单号，到账后手工开通，适合要签合同走流程的公司。</p>
      <p><strong>发票</strong>：下单时填抬头和税号，电子普票当天开。</p>
    </div>
    <div class="panel panel-pad">
      <div class="panel-t">付款方式</div>
      <ul class="ticks">
        <li>微信支付（扫码）</li>
        <li>支付宝（扫码 / 电脑网站支付）</li>
        <li>对公转账：客服给账号，到账后手工开通</li>
      </ul>
      <p class="tiny dim" style="margin-top:14px;margin-bottom:0">
        付款卡住就加客服 QQ <?= e(cfg('site.support')) ?>，报订单号。
      </p>
    </div>
  </div>
</section>

<?php
/**
 * 个人版专属首页 —— 清爽工作台风：天蓝渐变 hero、居中布局、圆角卡片。
 * 面向个人与小团队，语气亲和，突出「够用就好、数据归你」。
 */
$cards = [
    ['🔗', '短链好管理', '密码、有效期、点击上限一条链一套规则；分组收纳，找链接不用翻。'],
    ['📊', '统计看得懂', '按天 / 来源 / 设备三个维度看数，每次点击单独留痕，投诉来了不抓瞎。'],
    ['🏠', '域名是自己的', '绑定你自己的域名发短链，品牌不挂在别人名下，数据也不出门。'],
];
$plans = [
    ['月付', '¥29', '按月订阅，随时停', '/buy?plan=personal_monthly'],
    ['年付', '¥299', '一次管一年，省 7 个月', '/buy?plan=personal_yearly'],
    ['买断', '¥699', '一次付清永久用', '/buy?plan=personal'],
];
?>
<?php $banner = \App\Config::get('app.demo_banner'); ?>
<style>
.fk-personal{--ac:#0284c7;--ac2:#38bdf8;--fg:#0f172a;--dim:#475569}
.fk-personal .fp-hero{background:linear-gradient(160deg,#e0f2fe 0%,#f0f9ff 55%,#fff 100%);padding:72px 0 60px;text-align:center}
.fk-personal .fp-wrap{max-width:820px;margin:0 auto;padding:0 24px}
.fk-personal .fp-pill{display:inline-block;background:#fff;color:var(--ac);font-size:13px;font-weight:600;border-radius:999px;padding:6px 18px;box-shadow:0 1px 6px rgba(2,132,199,.15)}
.fk-personal h1{font-size:38px;line-height:1.25;margin:20px 0 0;color:var(--fg);font-weight:800}
.fk-personal .fp-lead{color:var(--dim);margin-top:16px;line-height:1.8;font-size:16px}
.fk-personal .fp-act{margin-top:28px}
.fk-personal .fp-btn{display:inline-block;background:var(--ac);color:#fff;padding:12px 30px;border-radius:12px;text-decoration:none;font-size:15px;font-weight:700;box-shadow:0 4px 14px rgba(2,132,199,.3)}
.fk-personal .fp-ghost{display:inline-block;margin-left:10px;color:var(--ac);text-decoration:none;font-size:14px;font-weight:600;padding:12px 10px}
.fk-personal .fp-cards{max-width:960px;margin:-34px auto 0;padding:0 24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px}
.fk-personal .fp-card{background:#fff;border-radius:16px;padding:28px 24px;box-shadow:0 8px 28px rgba(15,23,42,.08)}
.fk-personal .fp-card .ico{font-size:30px}
.fk-personal .fp-card h3{margin:12px 0 8px;font-size:17px;color:var(--fg)}
.fk-personal .fp-card p{margin:0;color:var(--dim);font-size:13.5px;line-height:1.75}
.fk-personal .fp-price{max-width:960px;margin:48px auto 0;padding:0 24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}
.fk-personal .fp-plan{border:1px solid #e2e8f0;border-radius:14px;padding:20px;text-align:center;background:#fff}
.fk-personal .fp-plan .p{font-size:26px;font-weight:800;color:var(--ac)}
.fk-personal .fp-plan .n{font-size:14px;color:var(--fg);font-weight:600;margin-top:2px}
.fk-personal .fp-plan .d{font-size:12px;color:var(--dim);margin-top:6px}
.fk-personal .fp-plan a{display:block;margin-top:14px;color:var(--ac);font-size:13px;font-weight:700;text-decoration:none}
.fk-personal .fp-demo{max-width:820px;margin:44px auto 0;padding:0 24px 8px;text-align:center}
.fk-personal .fp-demo p{display:inline-block;background:#f0f9ff;border:1px dashed var(--ac2);border-radius:12px;padding:14px 22px;font-size:13px;color:var(--dim);margin:0}
.fk-personal .fp-demo code{color:var(--ac);font-weight:600}
</style>
<div class="fk-personal">
  <div class="fp-hero">
    <div class="fp-wrap">
      <span class="fp-pill">FulkLink · 个人版</span>
      <h1>你的短链，你的域名，你的数据</h1>
      <p class="fp-lead">给个人和小团队的短链工作台：发出去的每条链接都能管、能看、能收回来。装在自己服务器上，不托管、不经过第三方。</p>
      <div class="fp-act">
        <a class="fp-btn" href="<?= path('/login') ?>">登录体验演示</a>
        <?php if ($banner): ?><a class="fp-ghost" href="<?= path('/pricing') ?>">对比四个版本 →</a><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="fp-cards">
    <?php foreach ($cards as $c): ?>
      <div class="fp-card"><div class="ico"><?= $c[0] ?></div><h3><?= e($c[1]) ?></h3><p><?= e($c[2]) ?></p></div>
    <?php endforeach; ?>
  </div>
  <?php if ($banner): ?>
  <div class="fp-price">
    <?php foreach ($plans as $pl): ?>
      <div class="fp-plan"><div class="n"><?= e($pl[0]) ?></div><div class="p"><?= e($pl[1]) ?></div><div class="d"><?= e($pl[2]) ?></div><a href="<?= e($pl[3]) ?>">立即购买 →</a></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($banner): ?>
  <div class="fp-demo">
    <p>演示账号 <code>demo@fulkteam.cn</code> / <code>user@fulkteam.cn</code>　密码 <code>FulkDemo2026</code>　·　数据每天凌晨自动重置，随便玩</p>
  </div>
  <?php endif; ?>
</div>

<?php
/**
 * 专业版专属首页 —— 数据科技风：左编号卖点列表 + 右模拟统计面板，深蓝主色。
 * 强调团队协作、数据资产与规模化管理。
 */
$points = [
    ['01', '规模化链接治理', '3 个自定义域名、5 个成员账号、批量操作与链接分组，多品牌多活动一后台收口。'],
    ['02', '数据完整可带走', '逐次点击明细 + 全维度报表，CSV 一键导出——数据是资产，不能锁死在系统里。'],
    ['03', '云端风控加持', 'IP 黑名单、模板策略由云端统一下发，恶意流量在跳转前就被拦住。'],
    ['04', '优先支持通道', '专属技术群 + 更新优先排期，出了问题不等公共队列。'],
];
$panel = [
    ['今日点击', '12,847'],
    ['活跃短链', '3,206'],
    ['成员', '5'],
    ['自定义域名', '3'],
];
?>
<?php $banner = \App\Config::get('app.demo_banner'); ?>
<style>
.fk-pro{--navy:#0f2a4a;--ac:#1d4ed8;--fg:#0f172a;--dim:#64748b;--line:#e2e8f0}
.fk-pro .fpro-hero{background:linear-gradient(120deg,var(--navy) 0%,#123a6b 60%,#1d4ed8 130%);color:#fff;padding:70px 0 64px}
.fk-pro .fpro-wrap{max-width:1040px;margin:0 auto;padding:0 24px;display:grid;grid-template-columns:1.1fr .9fr;gap:44px;align-items:center}
.fk-pro .fpro-tag{font-size:12px;letter-spacing:3px;color:#93c5fd;font-weight:700}
.fk-pro h1{font-size:36px;line-height:1.28;margin:14px 0 0;font-weight:800}
.fk-pro .fpro-lead{color:#cbd5e1;margin-top:14px;line-height:1.8;font-size:15px}
.fk-pro .fpro-btns{margin-top:26px;display:flex;gap:12px;flex-wrap:wrap}
.fk-pro .fpro-btn{display:inline-block;background:#fff;color:var(--navy);padding:11px 26px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px}
.fk-pro .fpro-btn.ghost{background:transparent;border:1px solid #64748b;color:#e2e8f0;font-weight:600}
.fk-pro .fpro-panel{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);border-radius:14px;padding:24px;backdrop-filter:blur(4px)}
.fk-pro .fpro-panel .t{font-size:12px;color:#93c5fd;letter-spacing:2px;margin-bottom:14px;font-weight:700}
.fk-pro .fpro-stats{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.fk-pro .fpro-stat .v{font-size:26px;font-weight:800;font-family:ui-monospace,Menlo,Consolas,monospace}
.fk-pro .fpro-stat .k{font-size:12px;color:#94a3b8;margin-top:2px}
.fk-pro .fpro-bars{margin-top:16px;display:flex;align-items:flex-end;gap:6px;height:52px}
.fk-pro .fpro-bars i{flex:1;background:linear-gradient(180deg,#60a5fa,#1d4ed8);border-radius:3px 3px 0 0;display:block}
.fk-pro .fpro-body{max-width:1040px;margin:0 auto;padding:56px 24px 24px;display:grid;grid-template-columns:1fr 1fr;gap:44px}
.fk-pro .fpro-body.fpro-body-solo{grid-template-columns:1fr;max-width:760px}
@media(max-width:860px){.fk-pro .fpro-wrap,.fk-pro .fpro-body{grid-template-columns:1fr}}
.fk-pro .fpro-pt{display:flex;gap:16px;padding:18px 0;border-bottom:1px solid var(--line)}
.fk-pro .fpro-pt:last-child{border-bottom:0}
.fk-pro .fpro-pt .no{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:22px;font-weight:800;color:var(--ac);opacity:.55}
.fk-pro .fpro-pt h3{margin:0 0 6px;font-size:16px;color:var(--fg)}
.fk-pro .fpro-pt p{margin:0;color:var(--dim);font-size:13.5px;line-height:1.75}
.fk-pro .fpro-side{background:#f8fafc;border:1px solid var(--line);border-radius:14px;padding:28px;text-align:center;height:fit-content}
.fk-pro .fpro-side .p{font-size:38px;font-weight:800;color:var(--ac)}
.fk-pro .fpro-side .u{color:var(--dim);font-size:14px;margin-top:4px}
.fk-pro .fpro-side a{display:block;margin:18px auto 0;background:var(--ac);color:#fff;padding:11px 30px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;max-width:220px}
.fk-pro .fpro-demo{font-size:12.5px;color:var(--dim);margin-top:14px;line-height:1.7}
</style>
<div class="fk-pro">
  <div class="fpro-hero">
    <div class="fpro-wrap">
      <div>
        <div class="fpro-tag">FULKLINK · PROFESSIONAL</div>
        <h1>当链接多到管不过来，就该换专业版了</h1>
        <p class="fpro-lead">3 个域名、5 个成员、批量与分组、CSV 全量导出，再加一层云端风控。为把短链当正经业务跑的团队准备。</p>
        <div class="fpro-btns">
          <a class="fpro-btn" href="<?= path('/login') ?>">进入演示后台</a>
          <?php if ($banner): ?><a class="fpro-btn ghost" href="<?= path('/buy') ?>?plan=pro">¥1,980 / 年</a><?php endif; ?>
        </div>
      </div>
      <div class="fpro-panel">
        <div class="t">LIVE DASHBOARD · 演示账号可见</div>
        <div class="fpro-stats">
          <?php foreach ($panel as $s): ?>
            <div class="fpro-stat"><div class="v"><?= e($s[1]) ?></div><div class="k"><?= e($s[0]) ?></div></div>
          <?php endforeach; ?>
        </div>
        <div class="fpro-bars"><i style="height:40%"></i><i style="height:65%"></i><i style="height:52%"></i><i style="height:80%"></i><i style="height:100%"></i><i style="height:74%"></i><i style="height:88%"></i><i style="height:96%"></i></div>
      </div>
    </div>
  </div>
    <div class="fpro-body<?= $banner ? '' : ' fpro-body-solo' ?>">
      <div>
        <?php foreach ($points as $pt): ?>
          <div class="fpro-pt"><div class="no"><?= e($pt[0]) ?></div><div><h3><?= e($pt[1]) ?></h3><p><?= e($pt[2]) ?></p></div></div>
        <?php endforeach; ?>
      </div>
      <?php if ($banner): ?>
      <div class="fpro-side">
        <div class="p">¥1,980</div>
        <div class="u">每年 · 含全部专业版权益</div>
        <a href="<?= path('/buy') ?>?plan=pro">升级专业版</a>
        <p class="fpro-demo">演示账号 <b>demo@fulkteam.cn</b><br>密码 <b>FulkDemo2026</b><br>数据每天凌晨自动重置</p>
      </div>
      <?php endif; ?>
    </div>
</div>

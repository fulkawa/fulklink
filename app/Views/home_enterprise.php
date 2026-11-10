<?php
/**
 * 企业版专属首页 —— 暗黑高级风：深色底、金色强调、大标题、安全合规叙事。
 * 面向企业决策者，强调控制权、审计、API 集成与不限量。
 */
$quads = [
    ['审计日志', '每个管理动作、每次配置变更逐条留痕，可按人与时间检索，出事能追溯。'],
    ['开放 API', '完整 REST 接口，短链创建、统计查询、授权管理全部可编程接入内部系统。'],
    ['目标域名白名单', '跳转目标限定在审批过的域名清单内，防钓鱼、防滥用，合规检查直接过。'],
    ['云端风控', 'IP 黑名单、威胁情报与访问策略由云端统一下发，多层拦截在跳转之前。'],
];
$trust = ['域名与成员不限量', '专属客服通道', '对公转账与合同', '电子普票当天开'];
?>
<?php $banner = \App\Config::get('app.demo_banner'); ?>
<style>
.fk-ent{--bg:#0a0e14;--panel:#111827;--fg:#e6edf3;--dim:#8b98a9;--gold:#f0b429;--teal:#2dd4bf;--line:#1f2937;color:var(--fg)}
.fk-ent .fe-hero{background:radial-gradient(ellipse at 20% 0%,#12203a 0%,var(--bg) 55%);padding:84px 0 68px;border-bottom:1px solid var(--line)}
.fk-ent .fe-wrap{max-width:1080px;margin:0 auto;padding:0 24px}
.fk-ent .fe-kicker{font-size:12px;letter-spacing:4px;color:var(--gold);font-weight:700}
.fk-ent h1{font-size:46px;line-height:1.2;margin:18px 0 0;font-weight:800;max-width:760px}
.fk-ent h1 em{font-style:normal;color:var(--gold)}
.fk-ent .fe-lead{color:var(--dim);margin-top:18px;line-height:1.85;font-size:16px;max-width:680px}
.fk-ent .fe-act{margin-top:32px;display:flex;gap:14px;flex-wrap:wrap}
.fk-ent .fe-btn{display:inline-block;background:var(--gold);color:#0a0e14;padding:13px 32px;border-radius:6px;text-decoration:none;font-weight:800;font-size:14px;letter-spacing:.5px}
.fk-ent .fe-btn.ghost{background:transparent;border:1px solid #334155;color:var(--fg);font-weight:600}
.fk-ent .fe-quads{max-width:1080px;margin:-36px auto 0;padding:0 24px;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px}
.fk-ent .fe-quad{background:var(--panel);border:1px solid var(--line);border-top:2px solid var(--gold);border-radius:8px;padding:24px 22px}
.fk-ent .fe-quad h3{margin:0 0 10px;font-size:15px;color:var(--teal);letter-spacing:1px}
.fk-ent .fe-quad p{margin:0;font-size:13px;color:var(--dim);line-height:1.8}
.fk-ent .fe-price{max-width:1080px;margin:56px auto 0;padding:0 24px;display:grid;grid-template-columns:1fr 1fr;gap:44px;align-items:center}
@media(max-width:860px){.fk-ent .fe-price{grid-template-columns:1fr}}
.fk-ent .fe-price h2{font-size:28px;margin:0;line-height:1.4}
.fk-ent .fe-price .big{font-size:52px;font-weight:800;color:var(--gold);font-family:ui-monospace,Menlo,Consolas,monospace}
.fk-ent .fe-price .per{color:var(--dim);font-size:15px;margin-top:4px}
.fk-ent .fe-trust{margin-top:18px;display:flex;flex-wrap:wrap;gap:10px}
.fk-ent .fe-trust span{border:1px solid #334155;border-radius:999px;padding:5px 14px;font-size:12px;color:var(--dim)}
.fk-ent .fe-side{text-align:center}
.fk-ent .fe-side .tiny{font-size:12px;color:var(--dim);margin-top:12px;line-height:1.8}
</style>
<div class="fk-ent">
  <div class="fe-hero">
    <div class="fe-wrap">
      <div class="fe-kicker">FULKLINK · ENTERPRISE</div>
      <h1>链接是你的业务入口，<em>控制权必须完整</em></h1>
      <p class="fe-lead">全部 24 项能力一次放开：审计日志、开放 API、目标域名白名单、云端风控，域名与成员不限量。为企业合规与集成场景而生——出了事能追溯，接系统有接口，扩规模不用谈价。</p>
      <div class="fe-act">
        <a class="fe-btn" href="<?= $banner ? path('/login') : path('/register') ?>"><?= $banner ? '进入演示后台' : '免费注册使用' ?></a>
        <?php if ($banner): ?><a class="fe-btn ghost" href="<?= path('/buy') ?>?plan=enterprise_commercial">获取报价 · 对公与合同</a><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="fe-quads">
    <?php foreach ($quads as $q): ?>
      <div class="fe-quad"><h3><?= e($q[0]) ?></h3><p><?= e($q[1]) ?></p></div>
    <?php endforeach; ?>
  </div>
  <?php if ($banner): ?>
  <div class="fe-price">
    <div>
      <h2>商业版对外运营，私用版内部管理</h2>
      <p style="color:var(--dim);line-height:1.85;font-size:14.5px">商业版解锁对外落地页：你的访客打开你的域名先看到产品门面，注册进来用——你的客户你做主。部署在你自己的服务器，授权绑域名，到期续费沿用原码。</p>
      <div class="fe-trust">
        <?php foreach ($trust as $t): ?><span><?= e($t) ?></span><?php endforeach; ?>
      </div>
    </div>
    <div class="fe-side">
      <div class="big">¥12,800</div>
      <div class="per">企业商业版每年 · 含对外落地页</div>
      <a class="fe-btn" style="margin-top:20px" href="<?= path('/buy') ?>?plan=enterprise_commercial">立即采购</a>
      <p class="tiny">企业私用版 ¥6,800/年（不对外）<br>演示账号 demo@fulkteam.cn · 密码 FulkDemo2026<br>数据每日重置</p>
    </div>
  </div>
  <?php endif; ?>
</div>

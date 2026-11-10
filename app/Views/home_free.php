<?php
/**
 * 开源免费版专属首页 —— 开发者极简风。
 * 只在 EDITION_FORCE 锁档站点渲染（演示站）；官网与客户站不受影响。
 * 配色与布局刻意区别于官网与其它档位：等宽字体细节、左边框、朴素务实。
 */
$feats = [
    ['链接密码与有效期', '每条短链可单独设密码、到期时间与点击上限，到期立刻失效。'],
    ['分组与批量', '链接分组管理，CSV 批量导入，量大了也不乱。'],
    ['逐次点击记录', '每次点击单独存一行：时间、来源、设备、UA，IP 只存哈希。'],
    ['统计与导出', '按天 / 来源 / 设备三个维度看数，随时导出 CSV。'],
    ['目标域名白名单', '限定短链只能指向你批准的域名，防滥用。'],
    ['零依赖部署', 'PHP 8.0+ / MySQL 5.7+，不需要 Composer，不需要 Node，宝塔点几下就能跑。'],
];
?>
<?php $banner = \App\Config::get('app.demo_banner'); ?>
<style>
.fk-free{--fg:#1f2328;--dim:#57606a;--ac:#0969da;--bg:#f6f8fa;--line:#d0d7de;color:var(--fg)}
.fk-free .ff-hero{background:var(--bg);border-bottom:1px solid var(--line);padding:64px 0 56px}
.fk-free .ff-wrap{max-width:960px;margin:0 auto;padding:0 24px}
.fk-free .ff-badge{display:inline-block;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;color:var(--ac);border:1px solid var(--ac);border-radius:999px;padding:3px 14px;letter-spacing:.5px}
.fk-free h1{font-size:34px;line-height:1.3;margin:18px 0 0;padding-left:14px;border-left:4px solid var(--ac);font-weight:700}
.fk-free .ff-lead{color:var(--dim);margin-top:14px;max-width:640px;line-height:1.75;font-size:15px}
.fk-free .ff-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1px;background:var(--line);border:1px solid var(--line);margin-top:44px}
.fk-free .ff-cell{background:#fff;padding:22px 20px}
.fk-free .ff-cell h3{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:14px;margin:0 0 8px;color:var(--ac)}
.fk-free .ff-cell p{margin:0;font-size:13.5px;color:var(--dim);line-height:1.7}
.fk-free .ff-act{margin-top:36px;display:flex;gap:12px;flex-wrap:wrap}
.fk-free .ff-btn{display:inline-block;padding:10px 22px;border:1px solid var(--fg);border-radius:6px;color:var(--fg);text-decoration:none;font-size:14px;font-weight:600;background:#fff}
.fk-free .ff-btn.primary{background:var(--ac);border-color:var(--ac);color:#fff}
.fk-free .ff-demo{margin-top:40px;background:#fff;border:1px dashed var(--line);border-radius:8px;padding:18px 20px;font-size:13.5px;color:var(--dim)}
.fk-free .ff-demo code{font-family:ui-monospace,Menlo,Consolas,monospace;background:var(--bg);padding:2px 7px;border-radius:4px;color:var(--fg)}
</style>
<div class="fk-free">
  <div class="ff-hero">
    <div class="ff-wrap">
      <span class="ff-badge">OPEN SOURCE · v1.0.4 · 永久免费</span>
      <h1>短链系统，装在你自己的服务器上</h1>
      <p class="ff-lead">源码公开、本地功能全开、不连任何云端。数据落在你自己的 MySQL 里，条款和隐私政策你自己说了算。这是演示站，登录后所有功能都能真用。</p>
      <div class="ff-grid">
        <?php foreach ($feats as $f): ?>
          <div class="ff-cell"><h3><?= e($f[0]) ?></h3><p><?= e($f[1]) ?></p></div>
        <?php endforeach; ?>
      </div>
      <div class="ff-act">
        <a class="ff-btn primary" href="<?= path('/login') ?>">登录演示后台</a>
        <a class="ff-btn" href="<?= path('/docs') ?>">安装文档</a>
        <a class="ff-btn" href="https://github.com/fulkawa/fulklink" target="_blank" rel="noopener noreferrer">GitHub 源码</a>
      </div>
      <?php if ($banner): ?>
      <div class="ff-demo">
        演示账号：<code>demo@fulkteam.cn</code>（管理员）/ <code>user@fulkteam.cn</code>（普通用户）　密码：<code>FulkDemo2026</code>　演示数据每天凌晨 4 点重置。
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

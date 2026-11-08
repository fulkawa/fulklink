<?php
/**
 * 全站头部。
 *
 * 两种角色两套导航：
 *   cloud  —— 官网，卖授权：产品、价格、文档、常见问题、更新
 *   client —— 客户自己的短链工具：短链、新建、域名、团队
 * 同一套模板分叉，比维护两份 layout 稳。
 */
declare(strict_types=1);

/** @var string $content */
$pageTitle = $title ?? '';
$nav = $nav ?? '';
$siteName = (string) cfg('app.name');
$fullTitle = $pageTitle !== '' ? $pageTitle . ' · ' . $siteName : $siteName . ' · ' . cfg('app.tagline');
$me = \App\Auth::user();
$isClient = \App\Cloud\Mode::isClient();
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($fullTitle) ?></title>
<meta name="description" content="<?= e($desc ?? cfg('app.tagline')) ?>">
<meta name="robots" content="<?= $isClient ? 'noindex, nofollow' : 'index, follow' ?>">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
</head>
<body>

<header class="site-top">
  <div class="wrap top-inner">
    <a class="brand" href="<?= path('/') ?>">
      <span class="brand-mark">FL</span>
      <span class="brand-name"><?= e($siteName) ?></span>
    </a>
    <nav class="top-nav">
      <?php if ($isClient): ?>
        <a class="<?= $nav === 'links' ? 'on' : '' ?>" href="<?= path('/links') ?>">短链</a>
        <a class="<?= $nav === 'new' ? 'on' : '' ?>" href="<?= path('/links/new') ?>">新建</a>
        <a class="<?= $nav === 'domains' ? 'on' : '' ?>" href="<?= path('/domains') ?>">域名</a>
        <a class="<?= $nav === 'team' ? 'on' : '' ?>" href="<?= path('/team') ?>">团队</a>
        <a class="<?= $nav === 'docs' ? 'on' : '' ?>" href="<?= path('/docs') ?>">使用说明</a>
      <?php else: ?>
        <a class="<?= $nav === 'home' ? 'on' : '' ?>" href="<?= path('/') ?>">产品</a>
        <a class="<?= $nav === 'pricing' ? 'on' : '' ?>" href="<?= path('/pricing') ?>">价格</a>
        <a class="<?= $nav === 'docs' ? 'on' : '' ?>" href="<?= path('/docs') ?>">文档</a>
        <a class="<?= $nav === 'faq' ? 'on' : '' ?>" href="<?= path('/faq') ?>">常见问题</a>
        <a class="<?= $nav === 'changelog' ? 'on' : '' ?>" href="<?= path('/changelog') ?>">更新</a>
      <?php endif; ?>
    </nav>
    <div class="top-act">
      <?php if ($me !== null): ?>
        <a class="btn ghost" href="<?= path($isClient ? '/account' : '/account') ?>">控制台</a>
        <form method="post" action="<?= path('/logout') ?>" style="display:inline;margin:0">
          <?= \App\Auth::csrfField() ?>
          <button class="btn primary" type="submit">退出</button>
        </form>
      <?php else: ?>
        <?php if (!$isClient): ?>
          <a class="btn ghost" href="<?= path('/license') ?>">查授权</a>
        <?php endif; ?>
        <a class="btn ghost" href="<?= path('/login') ?>">登录</a>
        <a class="btn primary" href="<?= path('/register') ?>">注册</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<main><?= $content ?></main>

<footer class="site-foot">
  <div class="wrap foot-inner">
    <div class="foot-col">
      <div class="foot-brand"><?= e($siteName) ?></div>
      <div class="tiny dim"><?= e(cfg('site.company')) ?></div>
    </div>
    <div class="foot-col foot-links">
      <?php if ($isClient): ?>
        <a href="<?= path('/links') ?>">短链</a>
        <a href="<?= path('/domains') ?>">域名</a>
        <a href="<?= path('/team') ?>">团队</a>
        <a href="<?= path('/docs') ?>">使用说明</a>
        <a href="<?= path('/legal/terms') ?>">服务条款</a>
      <?php else: ?>
        <a href="<?= path('/docs') ?>">安装文档</a>
        <a href="<?= path('/faq') ?>">常见问题</a>
        <a href="<?= path('/changelog') ?>">更新记录</a>
        <a href="<?= path('/license') ?>">查授权</a>
        <a href="<?= path('/legal/terms') ?>">服务条款</a>
        <a href="<?= path('/legal/privacy') ?>">隐私说明</a>
        <a href="<?= path('/legal/refund') ?>">退款规则</a>
      <?php endif; ?>
    </div>
    <div class="foot-col tiny dim">
      客服 QQ <?= e(cfg('site.support')) ?><br>
      <?php if (cfg('site.icp')): ?>
        <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener"><?= e(cfg('site.icp')) ?></a>
      <?php endif; ?>
    </div>
  </div>
</footer>

</body>
</html>

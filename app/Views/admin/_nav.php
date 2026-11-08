<?php
/**
 * 【开源版专用】后台导航。没有订单、授权、认证审核、云端。
 */
/** @var string $active */
$active = $active ?? '';
?>
<nav class="acct-nav">
  <div class="acct-group">运维</div>
  <a class="<?= $active === 'home' ? 'on' : '' ?>" href="<?= path('/admin') ?>">概览</a>
  <a class="<?= $active === 'users' ? 'on' : '' ?>" href="<?= path('/admin/users') ?>">用户</a>
  <a class="<?= $active === 'links' ? 'on' : '' ?>" href="<?= path('/admin/links') ?>">短链</a>

  <div class="acct-group">配置</div>
  <a class="<?= $active === 'settings' ? 'on' : '' ?>" href="<?= path('/admin/settings') ?>">设置</a>

  <div class="acct-group">其他</div>
  <a href="<?= path('/') ?>">回前台</a>
</nav>

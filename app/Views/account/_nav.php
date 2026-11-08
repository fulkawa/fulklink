<?php
/**
 * 【开源版专用】个人中心导航。没有授权、订单、实名。
 */
/** @var string $active */
$active = $active ?? '';
?>
<nav class="acct-nav">
  <div class="acct-group">账号</div>
  <a class="<?= $active === 'home' ? 'on' : '' ?>" href="<?= path('/account') ?>">概览</a>
  <a class="<?= $active === 'links' ? 'on' : '' ?>" href="<?= path('/links') ?>">我的短链</a>

  <div class="acct-group">其他</div>
  <a href="<?= path('/') ?>">回前台</a>
</nav>

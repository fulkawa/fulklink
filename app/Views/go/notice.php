<?php
/** @var string $heading */
/** @var string $message */
$home = $home ?? false;
?>
<div class="wrap" style="max-width:520px">
  <div class="auth-wrap" style="margin:56px auto">
    <div class="auth-card" style="text-align:center">
      <div class="done-mark" style="background:rgba(248,81,73,.12);color:var(--err);margin:0 auto 16px">!</div>
      <div class="auth-h" style="margin-bottom:8px"><?= e($heading) ?></div>
      <p class="auth-sub" style="margin-bottom:18px"><?= e($message) ?></p>

      <?php if ($home): ?>
        <a class="btn ghost" href="<?= path('/') ?>">回首页</a>
      <?php else: ?>
        <p class="tiny dim" style="margin:0">
          如果这个链接是别人发给你的，告诉对方一声。
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>

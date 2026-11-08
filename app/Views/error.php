<?php
/** @var int $code */
/** @var string $reason */
?>
<section class="wrap sect" style="padding:110px 0;text-align:center">
  <div style="font-size:64px;font-weight:700;letter-spacing:-2px;color:#2b333f"><?= e($code ?? 500) ?></div>
  <p class="dim" style="margin:12px 0 26px"><?= e($reason ?? '这个请求没有完成。') ?></p>
  <a class="btn ghost" href="<?= path('/') ?>">回首页</a>
</section>

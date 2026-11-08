<?php
use App\Shorten;

/** @var array $link */
/** @var string $short */
/** @var string $root */
$host = (string) $link['domain'] !== '' ? (string) $link['domain'] : Shorten::defaultHost();
?>
<div class="wrap" style="max-width:720px">
  <div class="acct-h" style="margin-top:28px">
    <h1>二维码</h1>
    <a class="tiny" href="<?= path('/links/' . (int) $link['id']) ?>" style="color:var(--accent)">回统计</a>
  </div>

  <?php if (($banner['text'] ?? '') !== ''): ?>
    <div class="alert" style="margin-bottom:14px"><?= e($banner['text']) ?></div>
  <?php endif; ?>

  <div class="acct-card" style="text-align:center;padding:28px 20px">
    <div id="qr" class="qr-box" style="display:inline-block;background:#fff;padding:14px;border-radius:10px"></div>
    <div style="margin-top:16px">
      <div class="key-value" style="justify-content:center"><span class="key-box" id="qr-url"><?= e($root) ?></span></div>
    </div>
    <p class="tiny dim" style="margin:12px 0 0">
      域名：<b><?= e($host) ?></b><?= (string) $link['domain'] === '' ? '（主域名）' : '' ?>
      <?php if ((string) $link['title'] !== ''): ?> · <?= e((string) $link['title']) ?><?php endif; ?>
    </p>

    <div style="margin-top:18px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
      <button class="btn primary" type="button" id="download">下载 PNG</button>
      <button class="btn ghost" type="button" id="copy">复制链接</button>
      <a class="btn ghost" href="<?= path('/links/' . (int) $link['id'] . '/edit') ?>">编辑</a>
    </div>
    <p class="tiny dim" style="margin:14px 0 0">
      印刷建议：二维码四周留白不要裁掉，最小边长不小于 2 厘米，深色印在浅色底上。
    </p>
  </div>

  <?php if ($short !== $root): ?>
    <div class="note-box">
      <b>两种地址都能用。</b>
      自定义域名下可以省掉 <code class="code">/r/</code>，短一点：<code class="code"><?= e($root) ?></code>；
      通用地址是 <code class="code"><?= e($short) ?></code>。二维码用的是短的那个。
    </div>
  <?php endif; ?>
</div>

<script src="<?= asset('js/qrcode.min.js') ?>"></script>
<script>
(function () {
  var url = document.getElementById('qr-url').textContent.trim();
  var box = document.getElementById('qr');
  new QRCode(box, {
    text: url,
    width: 320,
    height: 320,
    colorDark: '#111111',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
  });

  document.getElementById('download').addEventListener('click', function () {
    var canvas = box.querySelector('canvas');
    var data = '';
    if (canvas) {
      data = canvas.toDataURL('image/png');
    } else {
      var img = box.querySelector('img');
      if (img) { data = img.src; }
    }
    if (!data) { return; }
    var a = document.createElement('a');
    a.href = data;
    a.download = 'qr-<?= e((string) $link['slug']) ?>.png';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  });

  document.getElementById('copy').addEventListener('click', function () {
    var btn = this;
    var done = function () { btn.textContent = '已复制'; setTimeout(function () { btn.textContent = '复制链接'; }, 1200); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done, function () {});
    } else {
      var ta = document.createElement('textarea');
      ta.value = url;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (e) {}
      document.body.removeChild(ta);
    }
  });
})();
</script>

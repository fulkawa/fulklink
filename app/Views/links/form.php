<?php
use App\Auth;
use App\Cloud\Gate;
use App\Shorten;
use App\View;

/** @var array|null $link */
/** @var array $domains */
/** @var array $groups */
/** @var string $defaultHost */

$isEdit = $link !== null;
$action = $isEdit ? '/links/' . (int) $link['id'] . '/edit' : '/links/new';
$canPwd = Gate::allows('links.password');
$canExp = Gate::allows('links.expire');
$canGroup = Gate::allows('links.group');

$expiresValue = '';
if ($isEdit && ($link['expires_at'] ?? null) !== null) {
    $expiresValue = date('Y-m-d\TH:i', (string) strtotime((string) $link['expires_at']));
}
?>
<div class="wrap">
  <div class="acct">
    <?= View::partial('account/_nav', ['active' => $isEdit ? 'links' : 'new']) ?>

    <div class="acct-main">
      <?php if (($banner['text'] ?? '') !== ''): ?>
        <div class="alert" style="margin-bottom:14px"><?= e($banner['text']) ?></div>
      <?php endif; ?>
      <?php if (($err ?? '') !== ''): ?>
        <div class="alert" style="margin-bottom:14px;border-color:rgba(248,81,73,.4);color:var(--err)"><?= e($err) ?></div>
      <?php endif; ?>

      <div class="acct-h">
        <h1><?= $isEdit ? '编辑短链' : '新建短链' ?></h1>
        <a class="tiny" href="<?= path('/links') ?>" style="color:var(--accent)">回列表</a>
      </div>

      <?php if ($isEdit): ?>
        <div class="acct-card">
          <div class="lic-top">
            <span class="lic-key"><?= e(Shorten::rootUrl($link)) ?></span>
            <span class="tag <?= Shorten::state($link) === 'active' ? 'ok' : 'warn' ?>"><?= e(Shorten::stateLabel(Shorten::state($link))) ?></span>
          </div>
          <div class="lic-meta">
            <span>累计点击 <b><?= (int) $link['click_count'] ?></b></span>
            <span>创建于 <?= e(fmt_date((string) $link['created_at'], true)) ?></span>
            <a href="<?= path('/links/' . (int) $link['id']) ?>" style="color:var(--accent)">看统计</a>
            <a href="<?= path('/links/' . (int) $link['id'] . '/qr') ?>" style="color:var(--accent)">二维码</a>
          </div>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= path($action) ?>" class="acct-card">
        <?= Auth::csrfField() ?>

        <div class="field" style="margin-bottom:16px">
          <label for="target">目标地址 <span class="tiny dim">必填</span></label>
          <input class="input" type="text" id="target" name="target" required
                 value="<?= e($isEdit ? (string) $link['target_url'] : '') ?>"
                 placeholder="https://example.com/landing-page">
          <div class="field-tip">不写 https:// 会自动补上。带参数的地址可以原样粘贴。</div>
        </div>

        <div class="form-grid">
          <div class="field">
            <label for="domain">使用的域名</label>
            <select class="input" id="domain" name="domain">
              <option value="">主域名（<?= e($defaultHost) ?>）</option>
              <?php foreach ($domains as $d): ?>
                <?php if ((int) $d['verified'] === 1): ?>
                  <option value="<?= e((string) $d['host']) ?>" <?= $isEdit && (string) $link['domain'] === (string) $d['host'] ? 'selected' : '' ?>><?= e((string) $d['host']) ?></option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
            <div class="field-tip">
              <?php $verified = array_values(array_filter($domains, static fn ($x) => (int) $x['verified'] === 1)); ?>
              <?= $verified === [] ? '还没验证过自定义域名。' : '换域名后旧地址会失效，已经发出去的链接不会自动跳转。' ?>
            </div>
          </div>

          <div class="field">
            <label for="slug">后缀 <span class="tiny dim">留空自动生成</span></label>
            <input class="input" type="text" id="slug" name="slug"
                   value="<?= e($isEdit ? (string) $link['slug'] : '') ?>"
                   placeholder="summer-sale" autocomplete="off">
            <div class="field-tip">字母、数字、短横线、下划线，3–48 位。</div>
          </div>

          <div class="field">
            <label for="title">标题 <span class="tiny dim">只给自己看</span></label>
            <input class="input" type="text" id="title" name="title"
                   value="<?= e($isEdit ? (string) $link['title'] : '') ?>"
                   placeholder="双十一活动页">
          </div>

          <div class="field">
            <label for="group">分组<?= $canGroup ? '' : '（专业版）' ?></label>
            <input class="input" type="text" id="group" name="group" list="group-list"
                   value="<?= e($isEdit ? (string) ($link['group_name'] ?? '') : '') ?>"
                   placeholder="推广 / 内部 / 活动" <?= $canGroup ? '' : 'disabled' ?>>
            <datalist id="group-list">
              <?php foreach ($groups as $g): ?>
                <option value="<?= e($g['name']) ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </div>
        </div>

        <div class="lp-preview">
          预览：<b id="preview"><?= e($isEdit ? Shorten::rootUrl($link) : 'https://' . $defaultHost . '/r/（自动生成）') ?></b>
          <span class="tiny dim">← 建好后就是短链，点列表里的「复制」拿走</span>
        </div>

        <hr class="lp-hr">

        <div class="form-grid">
          <div class="field">
            <label for="password">访问密码<?= $canPwd ? '' : '（需个人版以上）' ?></label>
            <input class="input" type="text" id="password" name="password" autocomplete="new-password"
                   placeholder="<?= $isEdit && (string) $link['password_hash'] !== '' ? '已设置，留空不修改' : '不填就不需要密码' ?>"
                   <?= $canPwd ? '' : 'disabled' ?>>
            <?php if ($isEdit && (string) $link['password_hash'] !== ''): ?>
              <label class="checkline tiny" style="margin-top:6px">
                <input type="checkbox" name="clear_password" value="1"> 取消密码保护
              </label>
            <?php endif; ?>
            <div class="field-tip">访客要先输对密码才会跳转。</div>
          </div>

          <div class="field">
            <label for="expires_at">有效期到<?= $canExp ? '' : '（需个人版以上）' ?></label>
            <input class="input" type="datetime-local" id="expires_at" name="expires_at"
                   value="<?= e($expiresValue) ?>" <?= $canExp ? '' : 'disabled' ?>>
            <div class="field-tip">留空表示长期有效。过期后访问显示「链接已过期」。</div>
          </div>

          <div class="field">
            <label for="max_clicks">访问次数上限</label>
            <input class="input" type="number" id="max_clicks" name="max_clicks" min="0"
                   value="<?= (int) ($isEdit ? $link['max_clicks'] : 0) ?>">
            <div class="field-tip">0 表示不限。适合限量领取、一次性邀请。</div>
          </div>

          <div class="field">
            <label for="note">备注</label>
            <input class="input" type="text" id="note" name="note"
                   value="<?= e($isEdit ? (string) ($link['note'] ?? '') : '') ?>"
                   placeholder="投放在哪个渠道、谁给的">
          </div>
        </div>

        <?php if ($isEdit): ?>
          <hr class="lp-hr">
          <div class="field">
            <label for="status">状态</label>
            <select class="input" id="status" name="status">
              <option value="active" <?= (string) $link['status'] === 'active' ? 'selected' : '' ?>>正常</option>
              <option value="disabled" <?= (string) $link['status'] === 'disabled' ? 'selected' : '' ?>>停用（访问显示已停用）</option>
            </select>
          </div>
        <?php endif; ?>

        <div class="lp-form-act">
          <button class="btn primary" type="submit"><?= $isEdit ? '保存' : '创建' ?></button>
          <a class="btn ghost" href="<?= path('/links') ?>">取消</a>
          <?php if ($isEdit): ?>
            <span style="margin-left:auto"></span>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var host = <?= json_encode($defaultHost) ?>;
  var domainEl = document.getElementById('domain');
  var slugEl = document.getElementById('slug');
  var preview = document.getElementById('preview');
  if (!preview || !slugEl || !domainEl) return;

  function render() {
    var d = domainEl.value || host;
    var s = (slugEl.value || '').trim().replace(/^[-_]+|[-_]+$/g, '').toLowerCase();
    if (!s) { preview.textContent = 'https://' + d + '/r/（自动生成）'; return; }
    preview.textContent = 'https://' + d + '/' + s;
  }
  domainEl.addEventListener('change', render);
  slugEl.addEventListener('input', render);
})();
</script>

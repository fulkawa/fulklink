<?php
/**
 * 【开源版专用】后台设置。只有发信、短信、短链域名白名单三项。
 * 没有支付、实名、云端、授权——那些配置项在开源版里根本不存在。
 */
use App\Auth;
use App\View;

/** @var array $v */
/** @var bool $mailOk */
/** @var bool $smsOk */
/** @var bool $smsDebug */
$secret = static function (string $key) use ($v): string {
    return (string) ($v[$key] ?? '') !== '' ? '已设置，留空不修改' : '';
};
?>
<div class="wrap">
  <div class="acct">
    <?= View::partial('admin/_nav', ['active' => 'settings']) ?>

    <div class="acct-main">
      <?php if (($notice ?? '') !== ''): ?>
        <div class="alert" style="margin-bottom:16px;border-color:rgba(63,185,80,.35);color:var(--ok)"><?= e($notice) ?></div>
      <?php endif; ?>
      <?php if (($error ?? '') !== ''): ?>
        <div class="alert err" style="margin-bottom:16px"><?= e($error) ?></div>
      <?php endif; ?>

      <div class="acct-h">
        <h1>设置</h1>
        <span class="tiny dim">密钥类字段不回显，留空表示不改</span>
      </div>

      <form method="post" action="<?= path('/admin/settings') ?>">
        <?= Auth::csrfField() ?>

        <div class="acct-card">
          <h2>邮件 SMTP <span class="tag <?= $mailOk ? 'ok' : 'warn' ?>"><?= $mailOk ? '可用' : '未配置' ?></span></h2>
          <p class="sub">注册验证码、找回密码都走这里。国内主机多数封 25 端口，建议用 465（SSL）。</p>
          <div class="form-grid">
            <div class="field">
              <label for="mail_host">服务器地址</label>
              <input class="input" type="text" id="mail_host" name="mail_host" value="<?= e((string) $v['mail_host']) ?>" placeholder="smtp.exmail.qq.com">
            </div>
            <div class="field">
              <label for="mail_port">端口</label>
              <input class="input" type="text" id="mail_port" name="mail_port" value="<?= e((string) $v['mail_port']) ?>" placeholder="465">
            </div>
            <div class="field">
              <label for="mail_secure">加密方式</label>
              <select class="input" id="mail_secure" name="mail_secure">
                <?php foreach (['ssl' => 'SSL（465）', 'tls' => 'STARTTLS（587）', '' => '不加密（25）'] as $k => $label): ?>
                  <option value="<?= e($k) ?>" <?= (string) $v['mail_secure'] === (string) $k ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label for="mail_user">账号</label>
              <input class="input" type="text" id="mail_user" name="mail_user" value="<?= e((string) $v['mail_user']) ?>" placeholder="noreply@example.com">
            </div>
            <div class="field">
              <label for="mail_pass">密码 / 授权码</label>
              <input class="input" type="password" id="mail_pass" name="mail_pass" value="" placeholder="<?= e($secret('mail_pass')) ?>" autocomplete="new-password">
            </div>
            <div class="field">
              <label for="mail_from">发件人地址</label>
              <input class="input" type="text" id="mail_from" name="mail_from" value="<?= e((string) $v['mail_from']) ?>" placeholder="noreply@example.com">
            </div>
            <div class="field">
              <label for="mail_name">发件人名字</label>
              <input class="input" type="text" id="mail_name" name="mail_name" value="<?= e((string) $v['mail_name']) ?>" placeholder="短链工具">
            </div>
          </div>
        </div>

        <div class="acct-card">
          <h2>短信 <span class="tag <?= $smsOk ? 'ok' : 'warn' ?>"><?= $smsOk ? '可用' : '未配置' ?></span><?php if ($smsDebug): ?> <span class="tag warn">调试模式</span><?php endif; ?></h2>
          <p class="sub">手机验证码注册用。不配置就只能用邮箱注册。</p>
          <div class="form-grid">
            <div class="field">
              <label for="sms_key">AccessKey ID</label>
              <input class="input" type="text" id="sms_key" name="sms_key" value="" placeholder="<?= e($secret('sms_key')) ?>" autocomplete="off">
            </div>
            <div class="field">
              <label for="sms_secret">AccessKey Secret</label>
              <input class="input" type="password" id="sms_secret" name="sms_secret" value="" placeholder="<?= e($secret('sms_secret')) ?>" autocomplete="new-password">
            </div>
            <div class="field">
              <label for="sms_sign">短信签名</label>
              <input class="input" type="text" id="sms_sign" name="sms_sign" value="<?= e((string) $v['sms_sign']) ?>" placeholder="短链工具">
            </div>
            <div class="field">
              <label for="sms_template">模板 CODE</label>
              <input class="input" type="text" id="sms_template" name="sms_template" value="<?= e((string) $v['sms_template']) ?>" placeholder="SMS_123456789">
            </div>
          </div>
        </div>

        <div class="acct-card">
          <h2>短链域名</h2>
          <p class="sub">允许作为目标地址的域名，留空表示不限制。一行一个，支持 <code>*.example.com</code> 这种通配。</p>
          <div class="form-grid">
            <div class="field full">
              <label for="allowed_hosts">白名单</label>
              <textarea class="input" id="allowed_hosts" name="allowed_hosts" rows="4" placeholder="example.com&#10;*.example.com"><?= e((string) $v['allowed_hosts']) ?></textarea>
            </div>
          </div>
        </div>

        <div class="acct-card">
          <div class="full">
            <button class="btn primary" type="submit">保存</button>
          </div>
        </div>
      </form>

      <div class="acct-card">
        <h2>测试发信</h2>
        <p class="sub">先保存上面的 SMTP，再发一封试试。发不出去会把服务器的原话贴出来。</p>
        <form method="post" action="<?= path('/admin/mail-test') ?>" class="form-grid">
          <?= Auth::csrfField() ?>
          <div class="field">
            <label for="to">收件箱</label>
            <input class="input" type="email" id="to" name="to" placeholder="you@example.com" required>
          </div>
          <div class="full">
            <button class="btn" type="submit">发测试信</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

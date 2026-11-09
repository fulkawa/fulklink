<?php
use App\Auth;
use App\View;

$data = $latest['ok'] ? $latest['data'] : [];
$state = (string) ($updateStatus['status'] ?? 'unknown');
$needsRecovery = in_array($state, ['applying', 'rolling_back', 'recovery_required'], true);
$canRollback = in_array($state, ['installed', 'applying', 'rolling_back', 'recovery_required'], true)
    && !empty($updateStatus['backup']) && $backups !== [];
$stateLabel = [
    'idle' => '空闲', 'installed' => '已安装', 'applying' => '安装中或安装中断',
    'rolling_back' => '恢复中或恢复中断', 'recovery_required' => '需要恢复',
    'rolled_back' => '已回滚',
][$state] ?? '请检查更新记录';
$canInstall = !$needsRecovery && $state !== 'unknown' && $latest['ok'] && !empty($data['newer']) && !empty($data['php_ok'])
    && !empty($data['zip_ok']) && !empty($data['writable']) && ($data['edition'] ?? '') === $edition;
$updateDir = BASE_PATH . '/data/updates';
$writable = is_writable(BASE_PATH . '/app')
    && is_writable(is_dir($updateDir) ? $updateDir : BASE_PATH . '/data');
?>
<div class="wrap">
  <div class="acct">
    <?= View::partial('admin/_nav', ['active' => 'update']) ?>
    <div class="acct-main">
      <div class="acct-h">
        <h1>程序更新</h1>
        <span class="tag <?= $pushOn ? 'warn' : '' ?>"><?= $pushOn ? '推送已开启' : '推送已关闭' ?></span>
      </div>

      <?php if ($notice['text'] !== ''): ?>
        <div class="alert" role="status" style="margin-bottom:16px"><?= e($notice['text']) ?></div>
      <?php endif; ?>
      <?php if ($notice['key'] !== ''): ?>
        <div class="acct-card">
          <h2>新推送密钥 · 仅本次展示</h2>
          <p class="sub">请立即复制到发布端的密钥管理系统。离开此页后无法再次查看；遗失时只能重新生成。</p>
          <div class="field">
            <label for="new-push-key">本次生成的密钥</label>
            <input class="input mono" type="password" id="new-push-key" value="<?= e($notice['key']) ?>" readonly autocomplete="off" spellcheck="false">
          </div>
          <button class="btn ghost" type="button" id="copy-push-key">复制密钥</button>
          <span class="tiny dim" id="copy-push-status" role="status"></span>
        </div>
        <script>
        document.getElementById('copy-push-key').addEventListener('click', async function () {
          var field = document.getElementById('new-push-key');
          var status = document.getElementById('copy-push-status');
          try {
            await navigator.clipboard.writeText(field.value);
            status.textContent = '已复制，请妥善保管。';
          } catch (err) {
            field.focus();
            field.select();
            status.textContent = '自动复制不可用，请手动复制已选中的密钥。';
          }
        });
        </script>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert err" role="alert" style="margin-bottom:16px"><?= e($error) ?></div>
      <?php endif; ?>

      <div class="note-box">
        <b>首次升级说明：</b>1.0.2 及更早版本尚无推送入口，必须先手工备份并安装 1.0.3 引导版本，之后才能使用云端推送。
        推送默认关闭，必须由本站管理员主动授权。建议先在测试环境验证，确认后再更新生产站点。
      </div>

      <div class="acct-card">
        <h2>当前环境</h2>
        <dl class="kv">
          <dt>程序版本</dt><dd class="mono"><?= e($cur) ?></dd>
          <dt>发行类型</dt><dd><?= $edition === 'open' ? '开源版' : '商业版' ?> <span class="mono">(<?= e($edition) ?>)</span></dd>
          <dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd>
          <dt>ZIP 扩展</dt><dd><?= class_exists('ZipArchive') ? '可用' : '不可用，需启用 ZipArchive' ?></dd>
          <dt>目录权限预检</dt><dd><?= $writable ? 'app/ 与更新数据目录可写' : '请检查 app/ 与 data/updates/ 写入权限' ?></dd>
          <dt>最近更新</dt><dd><?= $lastAt === '' ? '暂无记录' : e($lastAt) ?></dd>
          <dt>持久化状态</dt><dd><?= e($stateLabel) ?> <span class="mono">(<?= e($state) ?>)</span></dd>
          <?php if (!empty($updateStatus['at'])): ?>
            <dt>状态记录时间</dt><dd><?= e($updateStatus['at']) ?></dd>
          <?php endif; ?>
        </dl>
        <?php if ($needsRecovery): ?>
          <div class="alert err" role="alert">存在未完成的更新记录。若更新请求仍在运行，请勿重复提交；若已中断，请先修复目录权限或磁盘空间问题，再使用下方回滚按钮恢复已验证的程序备份。不要删除 state.json 或备份来绕过保护，也不要继续安装新包。</div>
        <?php endif; ?>
        <?php if (!empty($updateStatus['error'])): ?>
          <p class="tiny" style="overflow-wrap:anywhere">最近错误：<?= e($updateStatus['error']) ?></p>
        <?php endif; ?>
        <p class="tiny dim">安装时仍会逐项检查文件权限与包内清单；环境预检通过不代表更新包一定可以安装。</p>
      </div>

      <div class="acct-card">
        <h2>更新清单 <?= !empty($latest['cached']) ? '<span class="tag">缓存</span>' : '' ?></h2>
        <?php if (!$latest['ok']): ?>
          <p class="sub"><?= e($latest['error']) ?></p>
        <?php else: ?>
          <dl class="kv">
            <dt>可用版本</dt><dd><?= e($data['version']) ?> · <?= !empty($data['newer']) ? '有新版本' : '没有更高版本' ?></dd>
            <dt>发行类型</dt><dd><?= e($data['edition']) ?></dd>
            <dt>最低 PHP</dt><dd><?= e($data['min_php']) ?> · <?= $data['php_ok'] ? '满足' : '不满足' ?></dd>
            <dt>包大小</dt><dd><?= number_format((int) $data['bytes']) ?> 字节</dd>
            <dt>发布时间</dt><dd><?= e($data['published_at'] ?: '未提供') ?></dd>
            <dt>SHA-256</dt><dd class="mono" style="overflow-wrap:anywhere"><?= e($data['sha256']) ?></dd>
          </dl>
          <?php if ($data['notes'] !== ''): ?>
            <h3>更新说明</h3>
            <div style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e($data['notes']) ?></div>
          <?php endif; ?>
        <?php endif; ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:16px">
          <form method="post" action="<?= path('/admin/update/check') ?>">
            <?= Auth::csrfField() ?>
            <button class="btn ghost" type="submit" <?= $source === '' ? 'disabled' : '' ?>>检查更新</button>
          </form>
          <form method="post" action="<?= path('/admin/update/install') ?>" onsubmit="return confirm('确认已备份站点和数据库，并安装最新的同版本类型更新包？');">
            <?= Auth::csrfField() ?>
            <button class="btn primary" type="submit" <?= $canInstall ? '' : 'disabled' ?>>下载并安装</button>
          </form>
        </div>
        <p class="tiny dim">安装前会重新取清单并验证 SHA-256、发行类型、版本及包内文件；不支持强制覆盖同版本或降级。</p>
      </div>

      <div class="acct-card">
        <h2>更新源与推送授权</h2>
        <p class="sub">推送密钥拥有更新程序文件的权限，只交给可信发布者；每个站点应使用独立密钥。</p>
        <form method="post" action="<?= path('/admin/update/config') ?>">
          <?= Auth::csrfField() ?>
          <div class="field">
            <label for="update-source">HTTPS 更新清单地址</label>
            <input class="input mono" type="url" name="source" id="update-source" maxlength="300" value="<?= e($source) ?>">
            <div class="field-tip">留空并保存即关闭清单拉取，不会恢复默认地址。推送开关独立控制。</div>
          </div>
          <div class="field">
            <label for="key-action">推送密钥：<?= $hasPushKey ? '已生成，不回显' : '尚未生成' ?></label>
            <select class="input" name="key_action" id="key-action">
              <option value="keep">保留现有密钥</option>
              <option value="new">生成新密钥（旧密钥立即失效）</option>
              <option value="clear">清空密钥并关闭推送</option>
            </select>
          </div>
          <label class="checkline" style="margin-bottom:14px">
            <input type="checkbox" name="push_enabled" value="1" <?= $pushOn ? 'checked' : '' ?>>
            我授权持有密钥的发布者向本站直接推送程序更新
          </label>
          <p class="tiny dim">没有密钥时无法开启。选择清空密钥将强制关闭推送，即使同时勾选了授权。</p>
          <button class="btn primary" type="submit">保存设置</button>
        </form>
      </div>

      <?php if ($canSend): ?>
        <div class="acct-card">
          <h2>向指定客户站推送更新</h2>
          <p class="sub">仅向本次指定的一个站点发送，不会广播。目标站管理员须先安装引导版本、生成密钥并开启推送。请核对域名并先在测试环境验证更新包。</p>
          <form method="post" action="<?= path('/admin/update/send') ?>" enctype="multipart/form-data" autocomplete="off" onsubmit="return confirm('确认已获目标站管理员授权，且更新包已在测试环境验证？本次仅更新指定站点。');">
            <?= Auth::csrfField() ?>
            <div class="field">
              <label for="send-target">目标站点 HTTPS 根地址</label>
              <input class="input mono" type="url" name="target" id="send-target" maxlength="300" required>
              <div class="field-tip">保留部署子目录，不带 /api/update/push；只允许公网 HTTPS 443 端口。</div>
            </div>
            <div class="field">
              <label for="send-key">该站推送密钥</label>
              <input class="input mono" type="password" name="target_key" id="send-key" value="" maxlength="64" autocomplete="new-password" required>
              <div class="field-tip">仅用于本次请求，不持久保存、不回显，也不写入日志。</div>
            </div>
            <div class="field">
              <label for="send-package">官方构建的 ZIP 更新包</label>
              <input class="input" type="file" name="package" id="send-package" accept=".zip,application/zip" required>
              <div class="field-tip">不超过 8 MiB，发行类型和新版本号从包内 MANIFEST.json 读取，不支持重包顶级目录、强制更新或降级。PHP upload_max_filesize 与 post_max_size 需允许上传。</div>
            </div>
            <button class="btn primary" type="submit">推送到指定站点</button>
          </form>
          <p class="tiny dim">若连接超时，请先在目标站检查实际版本及更新状态，确认结果后再操作，不要盲目重发。</p>
        </div>
      <?php endif; ?>

      <div class="acct-card">
        <h2>发布端推送说明</h2>
        <dl class="kv">
          <dt>接收地址</dt><dd class="mono" style="overflow-wrap:anywhere"><?= e($pushUrl) ?></dd>
          <dt>请求方式</dt><dd>POST · application/json</dd>
          <dt>签名协议</dt><dd>HMAC-SHA256 v2，绑定发行类型、版本与包哈希</dd>
        </dl>
        <p class="sub">在发布者运维仓库运行下方命令；将占位符替换为本站 HTTPS 根地址（保留部署子目录，不带 /api/update/push）、更新包路径与受限权限密钥文件路径。工具会自动拼接接收入口。客户端安装包不附带发布工具。</p>
        <pre class="mono" style="white-space:pre-wrap;overflow-wrap:anywhere">php bin/update-push.php --target="&lt;本站 HTTPS 根地址&gt;" --package="&lt;更新包.zip&gt;" --key-file="&lt;受限权限密钥文件&gt;"</pre>
        <p class="tiny dim">也可由密钥管理系统注入 FULKLINK_PUSH_KEY 环境变量并省略 --key-file。不要把明文密钥写进命令参数、URL 或日志。</p>
        <pre class="mono" style="white-space:pre-wrap;overflow-wrap:anywhere">JSON 字段：ts, nonce, edition, version, sha256, package, sig
package：ZIP 文件的 Base64；sha256：ZIP 文件的 SHA-256
签名文本：v2\n{ts}\n{nonce}\n{edition}\n{version}\n{sha256}</pre>
        <p class="tiny dim">请求体不超过 12 MiB，ZIP 不超过 8 MiB；使用新时间戳与唯一 nonce，保持服务器时间准确。只接受高于当前版本且发行类型相同的更新；不支持 force。403 表示未授权，401 表示验签失败，413 表示请求过大，409 表示更新冲突。</p>
      </div>

      <div class="acct-card">
        <h2>程序文件备份</h2>
        <p class="sub">仅恢复程序文件，不恢复数据库或业务数据。升级前请另行备份数据库、配置与上传文件。涉及数据库结构变化的版本不支持在线安装，需按发布说明手工迁移。</p>
        <?php if ($backups === []): ?>
          <p class="tiny dim">暂无更新备份。</p>
        <?php else: ?>
          <div style="overflow-x:auto">
            <table class="tbl">
              <thead><tr><th>备份文件</th><th>生成时间</th><th>大小</th></tr></thead>
              <tbody>
                <?php foreach ($backups as $backup): ?>
                  <tr><td class="mono"><?= e($backup['name']) ?></td><td><?= e($backup['at']) ?></td><td><?= number_format((int) $backup['size']) ?> 字节</td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <form method="post" action="<?= path('/admin/update/rollback') ?>" style="margin-top:16px" onsubmit="return confirm('仅恢复最近一次更新前的程序文件，不会回滚数据库。确认继续？');">
          <?= Auth::csrfField() ?>
          <button class="btn ghost" type="submit" <?= $canRollback ? '' : 'disabled' ?>><?= $needsRecovery ? '恢复中断的程序更新' : '回滚最近一次程序更新' ?></button>
        </form>
      </div>
    </div>
  </div>
</div>

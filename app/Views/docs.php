<?php
/**
 * 【开源版专用】使用说明。只讲装、用、边界，不涉及授权与云端。
 */
?>
<div class="wrap doc">
  <h1>使用说明</h1>
  <p class="lead">装好之后是这个样子：一套自己完全掌控的短链服务。下面从安装讲到日常使用。</p>

  <h2>一、安装</h2>
  <div class="steps">
    <div class="step">
      <div class="step-n">1</div>
      <div class="step-t">上传</div>
      <p>把这个目录里的全部文件传到网站根目录，Nginx 或 Apache 都行。PHP 要 8.0 以上。</p>
    </div>
    <div class="step">
      <div class="step-n">2</div>
      <div class="step-t">建库</div>
      <p>在主机面板里建一个 MySQL 5.7 / 8.0 的库，字符集 <code>utf8mb4</code>。记下库名、账号、密码。</p>
    </div>
    <div class="step">
      <div class="step-n">3</div>
      <div class="step-t">跑向导</div>
      <p>浏览器打开 <code>https://你的域名/install</code>。向导会先体检环境，再让你填数据库和管理员账号。装完自动生成 <code>.env</code> 和 <code>install.lock</code>。</p>
    </div>
    <div class="step">
      <div class="step-n">4</div>
      <div class="step-t">设伪静态</div>
      <p>所有请求转到 <code>index.php</code>。Nginx 用 <code>try_files $uri $uri/ /index.php?$query_string;</code>，Apache 用自带的 <code>.htaccess</code>。</p>
    </div>
  </div>

  <h2>二、建短链</h2>
  <p>登录后默认落在「短链」页。点「新建」填目标地址，标题可以留空，别名不填就自动生成六位。</p>
  <ul>
    <li><strong>跳转地址</strong>：<code>https://你的域名/r/别名</code>，302 跳转，不缓存。</li>
    <li><strong>二维码</strong>：每条链接都有，扫码即用。</li>
    <li><strong>统计</strong>：点进任意一条能看到按天趋势、来源、设备、浏览器。</li>
    <li><strong>导出</strong>：链接列表和点击明细都能导出 CSV，带 BOM，Excel 打开不乱码。</li>
  </ul>

  <h2>三、发信配置</h2>
  <p>注册验证码和找回密码依赖 SMTP。进「后台 → 设置」填服务器、端口、账号、授权码，保存后点「发测试信」验证。国内主机多数封 25 端口，建议用 465（SSL）。</p>
  <p>不发信也能用：把邮箱注册关掉、只留账号密码登录即可。</p>

  <h2>四、开源版不包含什么</h2>
  <p>这套开源版是完整可用的短链工具，但不是商业版。以下功能需要商业版：</p>
  <table class="tbl">
    <thead><tr><th>功能</th><th>开源版</th><th>商业版</th></tr></thead>
    <tbody>
      <tr><td>短链创建与跳转</td><td>支持</td><td>支持</td></tr>
      <tr><td>基础点击统计</td><td>支持</td><td>支持</td></tr>
      <tr><td>链接密码 / 有效期 / 分组</td><td>—</td><td>支持</td></tr>
      <tr><td>绑定自有域名</td><td>—</td><td>支持</td></tr>
      <tr><td>团队成员与开放 API</td><td>—</td><td>支持</td></tr>
      <tr><td>IP 归属地与风险域名拦截</td><td>—</td><td>支持</td></tr>
      <tr><td>操作审计</td><td>—</td><td>支持</td></tr>
    </tbody>
  </table>
  <p class="tiny dim">需要这些功能时，从官网买一个商业版授权码，换成商业版安装包即可，数据库可以原样迁移。</p>

  <h2>五、常见问题</h2>
  <div class="faq">
    <div class="faq-q">短链打不开，404</div>
    <div class="faq-a">九成是伪静态没配。确认所有路径都转到 <code>index.php</code>，而不是只转根目录。</div>
    <div class="faq-q">装完提示数据库连不上</div>
    <div class="faq-a">看 <code>.env</code> 里的四行：<code>DB_HOST</code>、<code>DB_NAME</code>、<code>DB_USER</code>、<code>DB_PASS</code>。主机名多数是 <code>127.0.0.1</code>，不是 <code>localhost</code>。</div>
    <div class="faq-q">想换个域名</div>
    <div class="faq-a">改 <code>.env</code> 里的 <code>SITE_URL</code>，清一下 <code>data/</code> 下的缓存即可。短链的后缀不会变，旧链接失效需要自己处理跳转。</div>
  </div>
</div>

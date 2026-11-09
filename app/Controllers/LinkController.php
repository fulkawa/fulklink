<?php
/**
 * 短链管理。前台控制台的核心页。
 *
 * 所有方法先过 need()：未登录跳登录页；登录了但属于别人团队时，
 * 数据边界用 Workspace::ownerId() 统一换算，控制器里不出现两种 id。
 *
 * 域名和团队只有所有者能动。成员可以建、改、删链接——这是「5 个成员」卖点的实际含义。
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Cloud\Gate;
use App\Db;
use App\Domains;
use App\LinkStats;
use App\Request;
use App\Response;
use App\Shorten;
use App\Ua;
use App\View;
use App\Workspace;

final class LinkController
{
    // ---------------- 列表 ----------------

    public function index(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        $user = Auth::user();
        $ownerId = Workspace::ownerId($user);
        $req = new Request();

        $filters = [
            'q'      => $req->str('q'),
            'status' => $req->str('status'),
            'group'  => $req->str('group'),
            'domain' => $req->str('domain'),
            'sort'   => $req->str('sort'),
        ];
        $page = max(1, $req->int('page', 1));
        $list = Shorten::listFor($ownerId, $filters, $page, 20);

        $groups = Shorten::groups($ownerId);
        $domains = Domains::listFor($ownerId);

        return Response::html(View::render('links/index', [
            'title'    => '短链',
            'nav'      => '',
            'user'     => $user,
            'list'     => $list,
            'filters'  => $filters,
            'groups'   => $groups,
            'domains'  => $domains,
            'overview' => LinkStats::overview($ownerId),
            'trend'    => LinkStats::ownerByDay($ownerId, 14),
            'top'      => LinkStats::topLinks($ownerId, 5),
            'used'     => Workspace::used($ownerId),
            'limit'    => [
                'links'   => Workspace::limit('links'),
                'domains' => Workspace::limit('domains'),
                'members' => Workspace::limit('members'),
            ],
            'isOwner'  => Workspace::isOwner($user),
            'msg'      => $req->str('msg'),
            'err'      => $req->str('err'),
            'banner'   => Gate::banner(),
            'planName' => Gate::planName(),
            'query'    => $this->queryString($filters),
        ]));
    }

    // ---------------- 新建 ----------------

    public function create(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        $user = Auth::user();
        $ownerId = Workspace::ownerId($user);

        return Response::html(View::render('links/form', [
            'title'   => '新建短链',
            'nav'     => '',
            'user'    => $user,
            'link'    => null,
            'domains' => Domains::listFor($ownerId),
            'groups'  => Shorten::groups($ownerId),
            'err'     => (string) (new Request())->str('err'),
            'banner'  => Gate::banner(),
            'defaultHost' => Shorten::defaultHost(),
        ]));
    }

    public function store(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        $req = new Request();
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->back('/links/new', '页面过期了，重试一次。', true);
        }

        $user = Auth::user();
        $owner = Workspace::owner($user);
        if ($owner === null) {
            return $this->back('/links', '账号状态异常，重新登录。', true);
        }

        $row = [
            'slug'       => $req->str('slug'),
            'target'     => $req->str('target'),
            'title'      => $req->str('title'),
            'domain'     => $req->str('domain'),
            'group'      => $req->str('group'),
            'note'       => $req->str('note'),
            'password'   => $req->str('password'),
            'expires_at' => $req->str('expires_at'),
            'max_clicks' => $req->int('max_clicks'),
        ];

        $res = Shorten::create($owner, $row);
        if (!$res['ok']) {
            return $this->back('/links/new', $res['message'], true);
        }

        // 建完直接进详情，比回列表更顺手——多数人建完要复制链接
        return $this->back('/links/' . $res['id'], '建好了。');
    }

    // ---------------- 编辑 ----------------

    public function edit(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        $user = Auth::user();
        $ownerId = Workspace::ownerId($user);
        $link = Shorten::find($ownerId, (int) ($params['id'] ?? 0));
        if ($link === null) {
            return $this->back('/links', '找不到这条链接。', true);
        }

        return Response::html(View::render('links/form', [
            'title'   => '编辑短链',
            'nav'     => '',
            'user'    => $user,
            'link'    => $link,
            'domains' => Domains::listFor($ownerId),
            'groups'  => Shorten::groups($ownerId),
            'err'     => (string) (new Request())->str('err'),
            'banner'  => Gate::banner(),
            'defaultHost' => Shorten::defaultHost(),
        ]));
    }

    public function update(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        $req = new Request();
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->back('/links', '页面过期了，重试一次。', true);
        }
        $user = Auth::user();
        $ownerId = Workspace::ownerId($user);
        $id = (int) ($params['id'] ?? 0);

        $link = Shorten::find($ownerId, $id);
        if ($link === null) {
            return $this->back('/links', '找不到这条链接。', true);
        }
        if ((string) $link['status'] === 'blocked') {
            return $this->back('/links/' . $id . '/edit', '这条链接已被管理员封禁，不能编辑或自行恢复。', true);
        }

        $in = [
            'target'     => $req->str('target'),
            'title'      => $req->str('title'),
            'domain'     => $req->str('domain'),
            'slug'       => $req->str('slug'),
            'group'      => $req->str('group'),
            'note'       => $req->str('note'),
            'expires_at' => $req->str('expires_at'),
            'max_clicks' => $req->int('max_clicks'),
        ];
        // 状态只接受白名单，显式传入才会被更新
        $status = $req->str('status');
        if ($status === 'active' || $status === 'disabled') {
            $in['status'] = $status;
        }
        if ($req->str('password') !== '') {
            $in['password'] = $req->str('password');
        }
        if ($req->str('clear_password') === '1') {
            $in['clear_password'] = '1';
            $in['password'] = '';
        }

        $res = Shorten::update($ownerId, $id, $in);
        if (!$res['ok']) {
            return $this->back('/links/' . $id . '/edit', $res['message'], true);
        }
        return $this->back('/links/' . $id, '改好了。');
    }

    public function delete(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        $req = new Request();
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->back('/links', '页面过期了，重试一次。', true);
        }
        $user = Auth::user();
        $id = (int) ($params['id'] ?? 0);
        if (!Shorten::delete(Workspace::ownerId($user), $id)) {
            return $this->back('/links', '删不掉，可能已经被删了。', true);
        }
        return $this->back('/links', '已删除。');
    }

    public function toggle(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        $req = new Request();
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->back('/links', '页面过期了，重试一次。', true);
        }
        $user = Auth::user();
        $ownerId = Workspace::ownerId($user);
        $id = (int) ($params['id'] ?? 0);
        $link = Shorten::find($ownerId, $id);
        if ($link === null) {
            return $this->back('/links', '找不到这条链接。', true);
        }
        if ((string) $link['status'] === 'blocked') {
            return $this->back('/links', '这条链接已被管理员封禁，不能自行启停。', true);
        }
        $next = (string) $link['status'] === 'active' ? 'disabled' : 'active';
        Db::update('links', ['status' => $next, 'updated_at' => now()], '`id` = ? AND `user_id` = ?', [$id, $ownerId]);
        Workspace::audit($ownerId, 'link.toggle', $id, $next === 'active' ? '启用' : '停用');
        return $this->back('/links', $next === 'active' ? '已启用。' : '已停用。');
    }

    // ---------------- 批量 ----------------

    public function bulk(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        $req = new Request();
        if (!Auth::checkCsrf($req->str('_csrf') ?: null)) {
            return $this->back('/links', '页面过期了，重试一次。', true);
        }
        $action = $req->str('action');
        if (in_array($action, ['enable', 'disable', 'delete', 'group'], true) && !Gate::allows('links.bulk')) {
            return $this->back('/links', Gate::reason('links.bulk'), true);
        }

        $ids = (array) $req->input('ids', []);
        $res = Shorten::bulk(Workspace::ownerId(Auth::user()), $ids, $action, $req->str('group'));
        if ($res['ok'] === 0 && $res['skipped'] === 0) {
            return $this->back('/links', '没选中任何链接。', true);
        }
        return $this->back('/links', '处理了 ' . $res['ok'] . ' 条' . ($res['skipped'] > 0 ? '，跳过 ' . $res['skipped'] . ' 条' : '') . '。');
    }

    // ---------------- 详情 / 统计 ----------------

    public function show(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        $user = Auth::user();
        $ownerId = Workspace::ownerId($user);
        $link = Shorten::find($ownerId, (int) ($params['id'] ?? 0));
        if ($link === null) {
            return $this->back('/links', '找不到这条链接。', true);
        }
        $req = new Request();
        $days = max(7, min(90, $req->int('days', 14)));
        $page = max(1, $req->int('page', 1));

        $canLog = Gate::allows('click.log');
        $canGeo = Gate::allows('cloud.geo');

        return Response::html(View::render('links/stats', [
            'title'   => '统计 · ' . $link['slug'],
            'nav'     => '',
            'user'    => $user,
            'link'    => $link,
            'summary' => LinkStats::summary((int) $link['id']),
            'days'    => $days,
            'daily'   => Gate::allows('stats.daily') ? LinkStats::byDay((int) $link['id'], $days) : [],
            'sources' => Gate::allows('stats.source') ? LinkStats::bySource((int) $link['id']) : [],
            'devices' => Gate::allows('stats.device') ? LinkStats::byDevice((int) $link['id']) : [],
            'browsers'=> Gate::allows('stats.device') ? LinkStats::byBrowser((int) $link['id']) : [],
            'regions' => $canGeo ? LinkStats::byRegion((int) $link['id']) : [],
            'log'     => $canLog ? LinkStats::recent((int) $link['id'], $page, 30) : null,
            'gates'   => [
                'daily'    => Gate::allows('stats.daily'),
                'source'   => Gate::allows('stats.source'),
                'device'   => Gate::allows('stats.device'),
                'geo'      => $canGeo,
                'log'      => $canLog,
                'export'   => Gate::allows('stats.export'),
            ],
            'reasons' => [
                'daily'  => Gate::reason('stats.daily'),
                'source' => Gate::reason('stats.source'),
                'device' => Gate::reason('stats.device'),
                'geo'    => Gate::reason('cloud.geo'),
                'log'    => Gate::reason('click.log'),
                'export' => Gate::reason('stats.export'),
            ],
            'msg'     => $req->str('msg'),
            'err'     => $req->str('err'),
            'banner'  => Gate::banner(),
        ]));
    }

    public function qr(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        $ownerId = Workspace::ownerId(Auth::user());
        $link = Shorten::find($ownerId, (int) ($params['id'] ?? 0));
        if ($link === null) {
            return $this->back('/links', '找不到这条链接。', true);
        }

        return Response::html(View::render('links/qr', [
            'title'  => '二维码 · ' . $link['slug'],
            'nav'    => '',
            'link'   => $link,
            'short'  => Shorten::shortUrl($link),
            'root'   => Shorten::rootUrl($link),
            'banner' => Gate::banner(),
        ]));
    }

    /** CSV 导出。统计页和列表页都能点 */
    public function export(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        if (!Gate::allows('stats.export')) {
            return $this->back('/links', Gate::reason('stats.export'), true);
        }
        $req = new Request();
        $ownerId = Workspace::ownerId(Auth::user());
        $linkId = $req->int('link', 0);
        if ($linkId > 0 && Shorten::find($ownerId, $linkId) === null) {
            return $this->back('/links', '找不到这条链接。', true);
        }

        $csv = LinkStats::exportCsv($ownerId, $linkId, $req->str('from'), $req->str('to'));
        $name = 'fulklink-clicks-' . date('Ymd-His') . '.csv';

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    // ---------------- 审计（企业版） ----------------

    public function audit(array $params = []): Response
    {
        if (($r = $this->need()) !== null) {
            return $r;
        }
        if (!Gate::allows('audit.log')) {
            return $this->back('/links', Gate::reason('audit.log'), true);
        }
        $req = new Request();
        $ownerId = Workspace::ownerId(Auth::user());
        $per = 50;
        $total = (int) Db::val('SELECT COUNT(*) FROM `link_audit` WHERE `user_id` = ?', [$ownerId], 0);
        $pages = max(1, (int) ceil($total / $per));
        $page = max(1, min($pages, $req->int('page', 1)));
        $offset = ($page - 1) * $per;

        $rows = Db::all(
            'SELECT a.*, u.`email`, u.`phone` FROM `link_audit` a
               LEFT JOIN `users` u ON u.`id` = a.`actor_id`
              WHERE a.`user_id` = ?
              ORDER BY a.`id` DESC LIMIT ' . $per . ' OFFSET ' . $offset,
            [$ownerId]
        );

        return Response::html(View::render('links/audit', [
            'title'  => '审计日志',
            'nav'    => '',
            'rows'   => $rows,
            'page'   => $page,
            'pages'  => $pages,
            'total'  => $total,
            'banner' => Gate::banner(),
        ]));
    }

    // ---------------- 内部 ----------------

    private function need(): ?Response
    {
        $user = Auth::user();
        if ($user === null) {
            $next = (new Request())->path();
            return Response::redirect(path('/login') . '?next=' . rawurlencode($next), 302);
        }
        return null;
    }

    private function back(string $path, string $message, bool $isError = false): Response
    {
        $key = $isError ? 'err' : 'msg';
        return Response::redirect(path($path) . '?' . $key . '=' . rawurlencode($message), 302);
    }

    /** 分页链接保留筛选条件 */
    private function queryString(array $filters): string
    {
        $keep = array_filter($filters, static fn ($v) => (string) $v !== '');
        return $keep === [] ? '' : '&' . http_build_query($keep);
    }
}

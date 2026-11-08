<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Response;
use App\View;

/**
 * 官网页面：首页、定价、接入文档、条款。
 * 这些页面不需要登录，也不碰数据库。
 */
final class PublicController
{
    public function home(array $params = []): Response
    {
        /*
         * 客户站（APP_MODE=client）打开首页要直接进自己的短链工具。
         * 让人先看到「买源码」的广告页是错的——这套程序已经是他买的了。
         */
        if (\App\Cloud\Mode::isClient()) {
            return \App\Auth::user() === null
                ? Response::redirect(path('/login'), 302)
                : Response::redirect(path('/links'), 302);
        }

        return Response::html(View::render('home', [
            'title' => '',
            'nav'   => 'home',
        ]));
    }

    public function pricing(array $params = []): Response
    {
        return Response::html(View::render('pricing', [
            'title' => '价格',
            'nav'   => 'pricing',
            'plans' => \App\Plans::all(),
        ]));
    }

    public function docs(array $params = []): Response
    {
        return Response::html(View::render('docs', [
            'title' => '接入文档',
            'nav'   => 'docs',
        ]));
    }

    public function faq(array $params = []): Response
    {
        return Response::html(View::render('faq', [
            'title' => '常见问题',
            'nav'   => 'faq',
        ]));
    }

    public function changelog(array $params = []): Response
    {
        return Response::html(View::render('changelog', [
            'title' => '更新记录',
            'nav'   => 'changelog',
        ]));
    }

    public function legal(array $params = []): Response
    {
        $doc = (string) ($params['doc'] ?? '');
        $allowed = ['terms' => '服务条款', 'privacy' => '隐私说明', 'refund' => '退款规则'];
        if (!isset($allowed[$doc])) {
            return new Response(View::render('error', [
                'title'  => '页面不存在',
                'code'   => 404,
                'reason' => '没有这份文档。',
            ]), 404);
        }
        return Response::html(View::render('legal', [
            'title' => $allowed[$doc],
            'nav'   => '',
            'doc'   => $doc,
            'docName' => $allowed[$doc],
        ]));
    }

    /** 部署自检用，不暴露任何配置值 */
    public function health(array $params = []): Response
    {
        $ok = true;
        $db = 'ok';
        try {
            \App\Db::val('SELECT 1');
        } catch (\Throwable $e) {
            $ok = false;
            $db = 'fail';
        }
        return Response::json([
            'status'  => $ok ? 'ok' : 'degraded',
            'db'      => $db,
            'version' => cfg('app.version'),
            'time'    => now(),
        ], $ok ? 200 : 503);
    }
}

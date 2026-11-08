<?php
/**
 * 能力码。授权决定「有哪些能力」，能力码决定「界面和行为」。
 *
 * 能力码用点号分层，客户端只认字符串，不认版本名。这样以后调整版本
 * 组合（比如把某个功能从专业版下放到个人版）只改服务端，客户站不用升级。
 *
 * 带 cloud. 前缀的是「离开云端就用不了」的能力，故意和本地能力区分开：
 * 客户想自己实现也没用，数据在云端。
 */
declare(strict_types=1);

namespace App\Cloud;

use App\Plans;

final class Features
{
    /** 所有版本都有，包括开源版 */
    private const BASE = [
        'links.create',
        'links.qr',
        'stats.basic',
    ];

    /** 按版本累加：personal 有 personal 档，pro 有 personal + pro，企业版全有 */
    private const GROUP = [
        'personal' => [
            'links.password',
            'links.expire',
            'stats.daily',
            'stats.source',
            'stats.device',
            'click.log',
            'domains.custom',
            'cloud.geo',
        ],
        'pro' => [
            'links.group',
            'links.bulk',
            'stats.export',
            'members.multi',
            'cloud.blocklist',
            'cloud.templates',
        ],
        'enterprise' => [
            'audit.log',
            'target.whitelist',
            'api.open',
            'cloud.scan',
            'cloud.benchmark',
            'cloud.relay',
            'domains.unlimited',
        ],
    ];

    /** 人话名字，后台和报错提示用 */
    public const LABEL = [
        'links.create'       => '创建短链',
        'links.qr'           => '二维码',
        'links.password'     => '链接密码',
        'links.expire'       => '有效期限制',
        'links.group'        => '链接分组与批量',
        'stats.basic'        => '基础统计',
        'stats.daily'        => '按天统计',
        'stats.source'       => '来源统计',
        'stats.device'       => '设备统计',
        'stats.export'       => '数据导出',
        'click.log'          => '逐次跳转记录',
        'domains.custom'     => '自定义域名',
        'domains.unlimited'  => '域名不限',
        'members.multi'      => '多成员',
        'audit.log'          => '审计日志',
        'target.whitelist'   => '目标域名白名单',
        'api.open'           => '开放 API',
        'cloud.geo'          => 'IP 归属地（云端）',
        'cloud.blocklist'    => '风险域名拦截（云端）',
        'cloud.templates'    => '模板库（云端）',
        'cloud.scan'         => '链接健康巡检（云端）',
        'cloud.benchmark'    => '行业基准（云端）',
        'cloud.relay'        => '多节点同步（云端）',
    ];

    public static function forPlan(string $plan): array
    {
        $group = Plans::group($plan);
        $rank = self::rank($group);
        $out = self::BASE;

        foreach (['personal', 'pro', 'enterprise'] as $g) {
            if ($rank >= self::rank($g)) {
                $out = array_merge($out, self::GROUP[$g]);
            }
        }

        return array_values(array_unique($out));
    }

    /** 能力包之外，还要告诉客户端能用多少额度 */
    public static function quota(string $plan): array
    {
        return [
            'links'   => Plans::quota($plan, 'links'),
            'domains' => Plans::quota($plan, 'domains'),
            'members' => Plans::quota($plan, 'members'),
        ];
    }

    public static function label(string $code): string
    {
        return self::LABEL[$code] ?? $code;
    }

    private static function rank(string $group): int
    {
        return ['free' => 0, 'personal' => 1, 'pro' => 2, 'enterprise' => 3][$group] ?? 0;
    }
}

<?php
/**
 * 【开源版专用】能力码桩。
 *
 * 开源版没有版本阶梯：所有本地能力全部放开（密码、有效期、分组、批量、
 * 明细统计、导出），不搞「演示版」。留在商业版的只有运维与云端能力：
 * 自定义域名、团队协作、开放 API、IP 归属地、云端风控。
 *
 * 这个文件只在构建开源包时替换进产物，主仓库（商业版）不受影响。
 */
declare(strict_types=1);

namespace App\Cloud;

use App\Plans;

final class Features
{
    /** 开源版固定能力：本地功能全给 */
    private const BASE = [
        'links.create',
        'links.qr',
        'stats.basic',
        'links.password',
        'links.expire',
        'links.group',
        'links.bulk',
        'stats.daily',
        'stats.source',
        'stats.device',
        'click.log',
        'stats.export',
        // 开源后台本来就提供「目标域名白名单」设置并保存 link.allowed_hosts，
        // 但此前能力表没放这个码，Shorten::validateUrl 的 Gate 判断永远跳过，
        // 配置形同虚设。安全能力不应被套餐开关隐式关闭。
        'target.whitelist',
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
        return array_values(self::BASE);
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
}

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

use App\Editions;

final class Features
{
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
        'landing.public'     => '对外落地页',
        'target.whitelist'   => '目标域名白名单',
        'api.open'           => '开放 API',
        'cloud.geo'          => 'IP 归属地（云端）',
        'cloud.blocklist'    => '风险域名拦截（云端）',
        'cloud.templates'    => '模板库（云端）',
        'cloud.scan'         => '链接健康巡检（云端）',
        'cloud.benchmark'    => '行业基准（云端）',
        'cloud.relay'        => '多节点同步（云端）',
    ];

    /**
     * 能力清单取自 App\Editions（唯一真源），本类只保留能力码的人话名字
     * 与历史调用入口。不要再在这里另写一份 BASE/GROUP —— 两份清单会漂移。
     */
    public static function forPlan(string $plan): array
    {
        return Editions::capabilities($plan);
    }

    /** 能力包之外，还要告诉客户端能用多少额度 */
    public static function quota(string $plan): array
    {
        return Editions::quota($plan);
    }

    public static function label(string $code): string
    {
        return self::LABEL[$code] ?? $code;
    }
}

<?php
/**
 * 企业版能力矩阵「唯一真源」。
 *
 * 把「4 组档位 → 能力 / 额度 / 策略」集中在这里配置，调用方只认本文件，
 * 不要再各自硬编码等级或能力清单。Cloud\Features 之后会委托给本类，
 * 所以这里刻意不 require / 不引用 Features，避免循环依赖；BASE 与 GROUP
 * 必须与 Cloud\Features 的常量逐项、逐序一致，这是向后兼容红线：
 * capabilities() 的返回值要和 Features::forPlan() 用 === 全等。
 *
 * 额度仍以 Plans 为准（0 = 不限），策略里 heartbeat_ttl / grace_hours
 * 全档恒定，保持已验证的线上行为；差异化只落在新增的三个字段上。
 */
declare(strict_types=1);

namespace App;

final class Editions
{
    /** 四组档位，顺序即等级顺序，不要改 */
    public const GROUPS = ['free', 'personal', 'pro', 'enterprise'];

    /** 所有档位都有，与 Cloud\Features::BASE 逐项一致（抄写，顺序不可变） */
    private const BASE = [
        'links.create',
        'links.qr',
        'stats.basic',
    ];

    /** 按档位累加，与 Cloud\Features::GROUP 逐项一致（抄写，顺序不可变） */
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

    /** 等级：free0 personal1 pro2 enterprise3；未知组一律降级 0 */
    private const RANK = [
        'free'       => 0,
        'personal'   => 1,
        'pro'        => 2,
        'enterprise' => 3,
    ];

    /**
     * 每组策略。键固定为 heartbeat_ttl / grace_hours / offline_grace_hours /
     * strict / max_retries。heartbeat_ttl 与 grace_hours 全档恒为 86400 / 72，
     * 与现有 CloudController 硬编码一致，避免改变已验证的线上行为。
     */
    private const POLICY = [
        'free' => [
            'heartbeat_ttl'      => 86400,
            'grace_hours'        => 72,
            'offline_grace_hours' => 0,
            'strict'             => false,
            'max_retries'        => 1,
        ],
        'personal' => [
            'heartbeat_ttl'      => 86400,
            'grace_hours'        => 72,
            'offline_grace_hours' => 24,
            'strict'             => false,
            'max_retries'        => 3,
        ],
        'pro' => [
            'heartbeat_ttl'      => 86400,
            'grace_hours'        => 72,
            'offline_grace_hours' => 48,
            'strict'             => true,
            'max_retries'        => 5,
        ],
        'enterprise' => [
            'heartbeat_ttl'      => 86400,
            'grace_hours'        => 72,
            'offline_grace_hours' => 72,
            'strict'             => true,
            'max_retries'        => 8,
        ],
    ];

    /** 每组取一个代表 plan code，供 quota / matrix 定位额度 */
    private const REPRESENTATIVE = [
        'free'       => 'free',
        'personal'   => 'personal',
        'pro'        => 'pro',
        'enterprise' => 'enterprise',
    ];

    /** 等级数值，未知组返回 0（降级到免费） */
    public static function rank(string $group): int
    {
        return self::RANK[$group] ?? 0;
    }

    /** plan code → 档位组，走 Plans::group() */
    public static function groupOfPlan(string $planCode): string
    {
        return Plans::group($planCode);
    }

    /**
     * 显式能力码数组。算法与 Cloud\Features::forPlan() 完全一致：
     * BASE + 按 rank 累加 personal / pro / enterprise，再 array_unique 重排索引。
     */
    public static function capabilities(string $planCode): array
    {
        $rank = self::rank(self::groupOfPlan($planCode));
        $out = self::BASE;
        foreach (['personal', 'pro', 'enterprise'] as $g) {
            if ($rank >= self::rank($g)) {
                $out = array_merge($out, self::GROUP[$g]);
            }
        }
        // 商业版专属：对外落地页（客户站访客可注册）。私用版没有这个能力。
        if (self::isCommercial($planCode)) {
            $out[] = self::CAP_LANDING;
        }
        return array_values(array_unique($out));
    }

    /** 商业版判定：plan code 以 _commercial 结尾（个人商业/专业商业/企业商业） */
    public static function isCommercial(string $planCode): bool
    {
        return str_ends_with($planCode, '_commercial');
    }

    public const CAP_LANDING = 'landing.public';

    /** 额度三元组，走 Plans::quota()，与 Cloud\Features::quota() 一致 */
    public static function quota(string $planCode): array
    {
        return [
            'links'   => Plans::quota($planCode, 'links'),
            'domains' => Plans::quota($planCode, 'domains'),
            'members' => Plans::quota($planCode, 'members'),
        ];
    }

    /** 该 plan 所属组的策略；未知组降级到 free */
    public static function policy(string $planCode): array
    {
        $group = self::groupOfPlan($planCode);
        return self::POLICY[$group] ?? self::POLICY['free'];
    }

    /** 是否具备某项能力；严格 in_array，空串/超长串/路径串都不会命中 */
    public static function allows(string $planCode, string $capability): bool
    {
        return in_array($capability, self::capabilities($planCode), true);
    }

    /** 完整矩阵：每组一行，行键固定为 group / capabilities / quota / policy */
    public static function matrix(): array
    {
        $matrix = [];
        foreach (self::GROUPS as $group) {
            $plan = self::REPRESENTATIVE[$group];
            $matrix[$group] = [
                'group'        => $group,
                'capabilities' => self::capabilities($plan),
                'quota'        => self::quota($plan),
                'policy'       => self::policy($plan),
            ];
        }
        return $matrix;
    }

    /**
     * 矩阵自洽检查：返回中文问题描述数组，空数组表示自洽。
     * 只依赖本文件常量与 Plans，不依赖 Features，避免循环依赖。
     */
    public static function validate(): array
    {
        $problems = [];

        // 1) 所有组都存在且配置齐全
        if (self::GROUPS !== ['free', 'personal', 'pro', 'enterprise']) {
            $problems[] = 'GROUPS 必须恰好为 free/personal/pro/enterprise 四组且顺序固定';
        }
        foreach (self::GROUPS as $group) {
            if (!array_key_exists($group, self::RANK)) {
                $problems[] = "组 {$group} 缺少 rank 定义";
            }
            if (!array_key_exists($group, self::POLICY)) {
                $problems[] = "组 {$group} 缺少 policy 定义";
            }
            if (!array_key_exists($group, self::REPRESENTATIVE)) {
                $problems[] = "组 {$group} 缺少代表 plan code";
            }
        }
        foreach (array_keys(self::GROUP) as $g) {
            if (!in_array($g, ['personal', 'pro', 'enterprise'], true)) {
                $problems[] = "GROUP 含未知累加档位 {$g}";
            }
        }
        foreach (['personal', 'pro', 'enterprise'] as $g) {
            if (!isset(self::GROUP[$g])) {
                $problems[] = "组 {$g} 缺少累加能力定义";
            }
        }

        // 2) 能力码格式（含点号）与 3) 无重复
        $allCodes = array_merge(self::BASE, ...array_values(self::GROUP));
        foreach ($allCodes as $code) {
            if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $code)) {
                $problems[] = "能力码格式不合法（须含点号、仅小写字母数字下划线）：{$code}";
            }
        }
        foreach (array_count_values($allCodes) as $code => $n) {
            if ($n > 1) {
                $problems[] = "能力码存在重复：{$code}（出现 {$n} 次）";
            }
        }

        // 4) 累加包含关系：free ⊂ personal ⊂ pro ⊂ enterprise（严格递增）
        for ($i = 1, $len = count(self::GROUPS); $i < $len; $i++) {
            $lowerGroup = self::GROUPS[$i - 1];
            $higherGroup = self::GROUPS[$i];
            $lower = self::capabilities(self::REPRESENTATIVE[$lowerGroup]);
            $higher = self::capabilities(self::REPRESENTATIVE[$higherGroup]);
            foreach ($lower as $code) {
                if (!in_array($code, $higher, true)) {
                    $problems[] = "累加包含关系断裂：{$lowerGroup} 的 {$code} 未出现在 {$higherGroup}";
                }
            }
            if (count($higher) <= count($lower)) {
                $problems[] = "累加包含关系断裂：{$higherGroup} 的能力数未严格多于 {$lowerGroup}";
            }
        }

        // 5) quota 非负且键齐全
        foreach (self::GROUPS as $group) {
            $q = self::quota(self::REPRESENTATIVE[$group]);
            foreach (['links', 'domains', 'members'] as $key) {
                if (!array_key_exists($key, $q)) {
                    $problems[] = "组 {$group} 的 quota 缺少键 {$key}";
                    continue;
                }
                if (!is_int($q[$key]) || $q[$key] < 0) {
                    $problems[] = "组 {$group} 的 quota.{$key} 必须是非负整数";
                }
            }
        }

        // 6) policy 键齐全、类型正确、向后兼容字段恒定
        $policyKeys = ['heartbeat_ttl', 'grace_hours', 'offline_grace_hours', 'strict', 'max_retries'];
        $intKeys = ['heartbeat_ttl', 'grace_hours', 'offline_grace_hours', 'max_retries'];
        foreach (self::GROUPS as $group) {
            if (!isset(self::POLICY[$group])) {
                continue;
            }
            $p = self::POLICY[$group];
            foreach ($policyKeys as $key) {
                if (!array_key_exists($key, $p)) {
                    $problems[] = "组 {$group} 的 policy 缺少键 {$key}";
                }
            }
            foreach ($intKeys as $key) {
                if (array_key_exists($key, $p) && (!is_int($p[$key]) || $p[$key] < 0)) {
                    $problems[] = "组 {$group} 的 policy.{$key} 必须是非负整数";
                }
            }
            if (array_key_exists('strict', $p) && !is_bool($p['strict'])) {
                $problems[] = "组 {$group} 的 policy.strict 必须是布尔值";
            }
            if (($p['heartbeat_ttl'] ?? null) !== 86400) {
                $problems[] = "组 {$group} 的 policy.heartbeat_ttl 必须恒为 86400（线上兼容）";
            }
            if (($p['grace_hours'] ?? null) !== 72) {
                $problems[] = "组 {$group} 的 policy.grace_hours 必须恒为 72（线上兼容）";
            }
        }

        // 7) rank 严格递增
        $prev = null;
        foreach (self::GROUPS as $group) {
            $r = self::rank($group);
            if ($prev !== null && $r <= $prev) {
                $problems[] = "rank 未严格递增：{$group} 的 rank {$r} 不大于前一组的 {$prev}";
            }
            $prev = $r;
        }

        return $problems;
    }
}

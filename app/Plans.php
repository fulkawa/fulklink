<?php
/**
 * 版本与价格。价格一律用分，避免浮点。
 * group 决定认证要求：personal 只需个人实名，pro/enterprise 需企业认证。
 */
declare(strict_types=1);

namespace App;

final class Plans
{
    public const FREE = 'free';

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        return self::$cache = [
            self::FREE => [
                'code'        => self::FREE,
                'name'        => '开源版',
                'suffix'      => '',
                'price'       => 0,
                'period_days' => 0,
                'group'       => 'free',
                'identity'    => 'none',
                'tag'         => '免费',
                'desc'        => '源码公开，自己部署自己改。功能有硬限制，拿来试或做小项目够用。',
                'quota'       => ['links' => 50, 'domains' => 1, 'members' => 1],
                'features'    => ['短链生成', '二维码', '基础点击统计（总量/按天）', '单用户', '页脚保留品牌'],
                'missing'     => ['链接密码', '有效期限制', '自定义域名', '开放 API', '导出'],
            ],
            'personal_monthly' => [
                'code'        => 'personal_monthly',
                'name'        => '个人版',
                'suffix'      => '月付',
                'price'       => 2900,
                'period_days' => 30,
                'group'       => 'personal',
                'identity'    => 'personal',
                'tag'         => '按月付',
                'desc'        => '30 天一期，到期续费。投放不多、想先装在自己机器上试试的，用这个就行。',
                'quota'       => ['links' => 500, 'domains' => 1, 'members' => 1],
                'features'    => ['短链与二维码', '按天/来源/设备统计', '逐次跳转记录', '链接密码与有效期', '1 个自定义域名'],
                'missing'     => [],
            ],
            'personal_yearly' => [
                'code'        => 'personal_yearly',
                'name'        => '个人版',
                'suffix'      => '年付',
                'price'       => 29900,
                'period_days' => 365,
                'group'       => 'personal',
                'identity'    => 'personal',
                'tag'         => '比月付省 49 元',
                'desc'        => '一次买 365 天，比按月续满一年少 49 块，中间不用管续费。',
                'quota'       => ['links' => 500, 'domains' => 1, 'members' => 1],
                'features'    => ['短链与二维码', '按天/来源/设备统计', '逐次跳转记录', '链接密码与有效期', '1 个自定义域名'],
                'missing'     => [],
            ],
            'personal' => [
                'code'        => 'personal',
                'name'        => '个人版',
                'suffix'      => '买断',
                'price'       => 69900,
                'period_days' => 0,
                'group'       => 'personal',
                'identity'    => 'personal',
                'tag'         => '一次买断',
                'desc'        => '付一次就一直能用，不过后面出的新版本不在这份授权里。',
                'quota'       => ['links' => 500, 'domains' => 1, 'members' => 1],
                'features'    => ['短链与二维码', '按天/来源/设备统计', '逐次跳转记录', '链接密码与有效期', '1 个自定义域名'],
                'missing'     => [],
            ],
            'pro' => [
                'code'        => 'pro',
                'name'        => '专业版',
                'suffix'      => '年付',
                'price'       => 198000,
                'period_days' => 365,
                'group'       => 'pro',
                'identity'    => 'personal',
                'tag'         => '3 域名 · 5 成员',
                'desc'        => '3 个域名、5 个成员，短链域名能换成自己的，链接单独设密码和有效期。',
                'quota'       => ['links' => 5000, 'domains' => 3, 'members' => 5],
                'features'    => ['个人版全部功能', '3 个自定义域名', '5 个成员账号', '链接分组与批量操作', '数据导出 CSV'],
                'missing'     => [],
            ],
            'enterprise' => [
                'code'        => 'enterprise',
                'name'        => '企业版',
                'suffix'      => '年付',
                'price'       => 680000,
                'period_days' => 365,
                'group'       => 'enterprise',
                'identity'    => 'enterprise',
                'tag'         => '不限量',
                'desc'        => '域名和成员都不限量。跳转记录留审计日志，目标域名可以加白名单，另外有开放 API。',
                'quota'       => ['links' => 0, 'domains' => 0, 'members' => 0],
                'features'    => ['专业版全部功能', '域名与成员不限', '审计日志', '目标域名白名单', '开放 API', '优先响应'],
                'missing'     => [],
            ],
        ];
    }

    public static function get(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }

    /** 前台定价页展示的档位（不含开源免费版） */
    public static function publicList(): array
    {
        return array_values(array_filter(self::all(), static fn ($p) => $p['code'] !== self::FREE));
    }

    public static function group(string $code): string
    {
        return self::all()[$code]['group'] ?? 'free';
    }

    /** 该版本要求的最低认证等级：none | personal | enterprise */
    public static function requiredIdentity(string $code): string
    {
        return self::all()[$code]['identity'] ?? 'none';
    }

    /** 版本额度：0 表示不限 */
    public static function quota(string $code, string $key): int
    {
        return (int) (self::all()[$code]['quota'][$key] ?? 0);
    }

    /** 授权类型：买断档（有价格但不算周期）签永久，其余签订阅 */
    public static function licenseType(string $code): string
    {
        $p = self::get($code);
        if ($p === null) {
            return 'subscription';
        }
        return ($p['period_days'] === 0 && $p['price'] > 0) ? 'perpetual' : 'subscription';
    }

    /** 授权天数：买断返回 0，调用方不传 duration_days */
    public static function durationDays(string $code): int
    {
        return (int) (self::get($code)['period_days'] ?? 0);
    }

    /** 可绑域名数，0 表示不限 */
    public static function maxDomains(string $code): int
    {
        return self::quota($code, 'domains');
    }

    /** 这个版本能不能直接买（免费版不签授权） */
    public static function sellable(string $code): bool
    {
        $p = self::get($code);
        return $p !== null && $p['price'] > 0;
    }

    public static function price(int $cents): string
    {
        return $cents === 0 ? '免费' : '¥' . number_format($cents / 100, 0);
    }

    public static function priceUnit(array $plan): string
    {
        if ($plan['price'] === 0) {
            return '';
        }
        if ($plan['period_days'] >= 300) {
            return '/ 年';
        }
        if ($plan['period_days'] > 0) {
            return '/ 30 天';
        }
        return '一次付清';
    }
}

<?php
/**
 * 功能闸门。业务代码只问这一句，不问授权细节。
 *
 *   if (!Gate::allows('cloud.blocklist')) { 返回降级视图 }
 *
 * cloud 模式下永远放行（官网自己不需要授权）；
 * client 模式下看能力包，拿不到就按免费版处理。
 *
 * 刻意不提供「一次性检查全部」的方法：集中在一处的判断最容易被一行注释掉。
 * 每个功能入口各自问一次，绕过成本高得多。
 */
declare(strict_types=1);

namespace App\Cloud;

final class Gate
{
    public static function allows(string $feature): bool
    {
        if ($feature === '') {
            return true;
        }
        return in_array($feature, Entitlement::current()['features'], true);
    }

    /** 不许的时候给人话理由，界面直接用 */
    public static function reason(string $feature): string
    {
        $ent = Entitlement::current();
        $label = Features::label($feature);

        if ($ent['state'] === 'expired' || $ent['state'] === 'expired_local') {
            return '「' . $label . '」需要有效授权。' . $ent['message'];
        }
        if ($ent['state'] === 'tampered') {
            return '「' . $label . '」已暂停：程序文件校验不通过。' . $ent['message'];
        }
        if ($ent['state'] === 'never') {
            return '「' . $label . '」需要有效授权。到后台「授权」里填入授权码即可启用。';
        }
        return '「' . $label . '」需要更高版本，当前是' . self::planName() . '。';
    }

    public static function planName(): string
    {
        $plan = Entitlement::plan();
        return \App\Plans::get($plan)['name'] ?? '免费版';
    }

    /** 视图顶部提示条用，没有要提示的就返回空串 */
    public static function banner(): array
    {
        $ent = Entitlement::current();
        if ($ent['state'] === 'grace') {
            return ['level' => 'warn', 'text' => (string) $ent['message']];
        }
        if ($ent['state'] === 'expired' || $ent['state'] === 'expired_local' || $ent['state'] === 'tampered') {
            return ['level' => 'err', 'text' => (string) $ent['message']];
        }
        if ($ent['state'] === 'never') {
            return ['level' => 'info', 'text' => '尚未绑定授权码，当前按免费版运行。'];
        }
        return ['level' => '', 'text' => ''];
    }
}

<?php
/**
 * 【开源版专用】心跳桩。
 *
 * 商业版靠心跳把授权状态、更新提示同步回来；开源版没有云端，
 * 这里必须是空实现——引导文件里那句 queue() 才不用改。
 */
declare(strict_types=1);

namespace App\Cloud;

final class Heartbeat
{
    public static function queue(): void
    {
        // 开源版不连云端
    }

    public static function send(): bool
    {
        return false;
    }

    public static function due(): bool
    {
        return false;
    }

    public static function lastAt(): int
    {
        return 0;
    }
}

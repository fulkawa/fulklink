<?php
/**
 * 工作区。一个工作区 = 一个所有者 + 若干成员，链接和域名都挂在所有者名下。
 *
 * 为什么要这层：客户站是装在自己服务器上的单实例，但企业版卖的是「5 个成员」。
 * 如果按 user_id 直接存，成员登录后看到的是空列表，等于没实现。
 * 所以统一用 owner_id 做数据边界，成员只是「替所有者操作」。
 *
 * 约束：一个用户同时只能属于一个工作区。跨工作区共享会让权限判断变成矩阵，
 * 这类系统不需要那么复杂，也容易配错。
 */
declare(strict_types=1);

namespace App;

use App\Cloud\Entitlement;

final class Workspace
{
    /** @var array<int,int> 同一请求内不重复查库 */
    private static array $ownerMemo = [];

    /** 当前用户的数据归属者。没加入任何团队时就是他自己 */
    public static function ownerId(?array $user = null): int
    {
        $user = $user ?? Auth::user();
        if ($user === null) {
            return 0;
        }
        $uid = (int) $user['id'];
        if (isset(self::$ownerMemo[$uid])) {
            return self::$ownerMemo[$uid];
        }

        // 只认 active：invited（待接受）/ disabled 都不切换数据归属，
        // 否则光凭一个邮箱就能把别人的账号在下一请求切进自己的工作区
        try {
            $row = Db::one(
                'SELECT `owner_id` FROM `team_members` WHERE `user_id` = ? AND `status` = ? LIMIT 1',
                [$uid, 'active']
            );
        } catch (\Throwable $e) {
            $row = null;
        }

        // 自己是所有者时，team_members 里不会有「自己是自己成员」的记录
        return self::$ownerMemo[$uid] = $row === null ? $uid : (int) $row['owner_id'];
    }

    public static function isOwner(?array $user = null): bool
    {
        $user = $user ?? Auth::user();
        return $user !== null && self::ownerId($user) === (int) $user['id'];
    }

    /** 所有者那一行 user 记录，用于显示站名、额度 */
    public static function owner(?array $user = null): ?array
    {
        $user = $user ?? Auth::user();
        if ($user === null) {
            return null;
        }
        $ownerId = self::ownerId($user);
        if ($ownerId === (int) $user['id']) {
            return $user;
        }
        return Db::one('SELECT * FROM `users` WHERE `id` = ? LIMIT 1', [$ownerId]);
    }

    /** 成员列表，含所有者自己 */
    public static function members(int $ownerId): array
    {
        $rows = Db::all(
            'SELECT m.`id` AS m_id, m.`role`, m.`status`, m.`created_at` AS joined_at,
                    u.`id`, u.`email`, u.`phone`, u.`name`, u.`last_login_at`
               FROM `team_members` m JOIN `users` u ON u.`id` = m.`user_id`
              WHERE m.`owner_id` = ?
              ORDER BY m.`id` ASC',
            [$ownerId]
        );
        $owner = Db::one('SELECT `id`, `email`, `phone`, `name`, `last_login_at` FROM `users` WHERE `id` = ?', [$ownerId]);
        if ($owner !== null) {
            array_unshift($rows, [
                'm_id'          => 0,
                'role'          => 'owner',
                'status'        => 'active',
                'joined_at'     => '',
                'id'            => (int) $owner['id'],
                'email'         => (string) $owner['email'],
                'phone'         => (string) $owner['phone'],
                'name'          => (string) $owner['name'],
                'last_login_at' => (string) $owner['last_login_at'],
            ]);
        }
        return $rows;
    }

    /**
     * 把一个已注册用户拉进工作区。
     *
     * @return array{ok:bool,message:string}
     */
    public static function add(int $ownerId, string $ident): array
    {
        $ident = Auth::normalizeIdent($ident);
        if ($ident === '') {
            return ['ok' => false, 'message' => '填对方注册用的邮箱或手机号。'];
        }

        $target = Auth::findByIdent($ident);
        if ($target === null) {
            return ['ok' => false, 'message' => '这个账号还没注册。让对方先在本站注册一个，再回来加。'];
        }
        $tid = (int) $target['id'];
        if ($tid === $ownerId) {
            return ['ok' => false, 'message' => '不用把自己加成成员。'];
        }
        if ((string) $target['status'] !== 'active') {
            return ['ok' => false, 'message' => '这个账号被停用了，加不进来。'];
        }
        // 系统管理员不该被挂到别人的工作区里，权限边界会乱
        if ((string) ($target['role'] ?? 'user') === 'admin') {
            return ['ok' => false, 'message' => '管理员账号不能被加为成员。'];
        }
        // 目标自己就是带成员的工作区所有者：不能同时去当别人的成员
        $owns = Db::one(
            'SELECT `id` FROM `team_members` WHERE `owner_id` = ? AND `status` = ? LIMIT 1',
            [$tid, 'active']
        );
        if ($owns !== null) {
            return ['ok' => false, 'message' => '这个账号自己是带成员的工作区所有者，不能同时加入别人的团队。'];
        }

        /*
         * 只发邀请，不直接入组：status='invited' 不进 ownerId()（那里只认 active），
         * 必须等目标本人确认。一个账号只能属于一个工作区，任何已有关系都挡住新邀请。
         */
        $existing = Db::all('SELECT `owner_id`, `status` FROM `team_members` WHERE `user_id` = ?', [$tid]);
        foreach ($existing as $row) {
            $rowOwner = (int) $row['owner_id'];
            if ($rowOwner === $ownerId) {
                return [
                    'ok'      => false,
                    'message' => (string) $row['status'] === 'active'
                        ? '这个人已经在你的团队里了。'
                        : '已经邀请过这个人了，等对方处理，或先移除旧记录再重发。',
                ];
            }
            if ((string) $row['status'] === 'active') {
                return ['ok' => false, 'message' => '这个人已经在别的工作区里，一个账号只能属于一个工作区。'];
            }
            return ['ok' => false, 'message' => '这个账号在别的工作区还有待处理的关系，一个账号只能属于一个工作区。'];
        }

        $limit = self::limit('members');
        if ($limit > 0 && count(self::members($ownerId)) >= $limit) {
            return ['ok' => false, 'message' => '成员数到上限了（' . $limit . ' 个）。升级版本可以加更多。'];
        }

        try {
            Db::insert('team_members', [
                'owner_id'   => $ownerId,
                'user_id'    => $tid,
                'role'       => 'member',
                'status'     => 'invited',
                'created_at' => now(),
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return ['ok' => false, 'message' => '已经邀请过这个人了。'];
            }
            throw $e;
        }

        Db::log('team.invite', '发出入组邀请 #' . $tid, ['owner_id' => $ownerId, 'user_id' => $tid]);
        self::audit($ownerId, 'team.invite', 0, '邀请 ' . Auth::identOf($target));
        return ['ok' => true, 'message' => '邀请已发给 ' . Auth::identOf($target) . '，等对方在「团队」页接受后才会加入。'];
    }

    /** 发给某用户的待接受邀请（ownerId() 查不到这些记录，得单独取） */
    public static function invitesFor(int $userId): array
    {
        return Db::all(
            'SELECT m.`id` AS m_id, m.`owner_id`, m.`created_at` AS invited_at,
                    u.`email`, u.`phone`, u.`name`
               FROM `team_members` m JOIN `users` u ON u.`id` = m.`owner_id`
              WHERE m.`user_id` = ? AND m.`status` = ?
              ORDER BY m.`id` ASC',
            [$userId, 'invited']
        );
    }

    /**
     * 被邀请人处理自己的邀请。$agree=true 接受，false 拒绝。
     * user_id 只能来自调用方传入的当前登录者（Auth::id()），查询里写死，
     * 不接收任何请求参数，保证「只能操作发给自己的邀请」。
     *
     * @return array{ok:bool,message:string}
     */
    public static function accept(int $userId, int $memberRowId, bool $agree): array
    {
        $row = Db::one(
            'SELECT * FROM `team_members` WHERE `id` = ? AND `user_id` = ? AND `status` = ? LIMIT 1',
            [$memberRowId, $userId, 'invited']
        );
        if ($row === null) {
            return ['ok' => false, 'message' => '没找到这条邀请，或已经处理过了。'];
        }

        if (!$agree) {
            Db::exec('DELETE FROM `team_members` WHERE `id` = ?', [$memberRowId]);
            Db::log('team.invite.reject', '拒绝入组邀请', ['owner_id' => (int) $row['owner_id'], 'user_id' => $userId]);
            return ['ok' => true, 'message' => '已拒绝这条邀请。'];
        }

        // 接受前再核一次：期间别被加到别的工作区去
        $busy = Db::one(
            'SELECT `id` FROM `team_members` WHERE `user_id` = ? AND `status` = ? AND `id` <> ? LIMIT 1',
            [$userId, 'active', $memberRowId]
        );
        if ($busy !== null) {
            return ['ok' => false, 'message' => '你已经在别的工作区了，一个账号只能属于一个工作区。'];
        }

        $ok = Db::exec(
            'UPDATE `team_members` SET `status` = ? WHERE `id` = ? AND `status` = ?',
            ['active', $memberRowId, 'invited']
        ) > 0;
        if (!$ok) {
            return ['ok' => false, 'message' => '邀请状态已变化，刷新页面重试。'];
        }
        // 同请求内 ownerId 的 memo 作废，避免刚接受还按「自己」算
        unset(self::$ownerMemo[$userId]);
        Db::log('team.invite.accept', '接受入组邀请', ['owner_id' => (int) $row['owner_id'], 'user_id' => $userId]);
        self::audit((int) $row['owner_id'], 'team.invite.accept', 0, '接受邀请 #' . $userId);
        return ['ok' => true, 'message' => '已加入团队，现在能看到工作区的链接了。'];
    }

    public static function remove(int $ownerId, int $memberRowId): bool
    {
        $row = Db::one('SELECT * FROM `team_members` WHERE `id` = ? AND `owner_id` = ? LIMIT 1', [$memberRowId, $ownerId]);
        if ($row === null) {
            return false;
        }
        Db::exec('DELETE FROM `team_members` WHERE `id` = ?', [$memberRowId]);
        self::audit($ownerId, 'team.remove', 0, '移除成员 #' . (int) $row['user_id']);
        Db::log('team.remove', '移除成员 #' . (int) $row['user_id'], ['owner_id' => $ownerId]);
        return true;
    }

    public static function setStatus(int $ownerId, int $memberRowId, string $status): bool
    {
        $status = $status === 'disabled' ? 'disabled' : 'active';
        // invited 走本人确认流程，不能靠 toggle 直接激活；active/disabled 行为保持原样
        return Db::exec(
            'UPDATE `team_members` SET `status` = ? WHERE `id` = ? AND `owner_id` = ? AND `status` <> ?',
            [$status, $memberRowId, $ownerId, 'invited']
        ) > 0;
    }

    /** 额度。0 = 不限 */
    public static function limit(string $key): int
    {
        return Entitlement::quota($key);
    }

    /** 当前用量 */
    public static function used(int $ownerId): array
    {
        return [
            'links'   => (int) Db::val('SELECT COUNT(*) FROM `links` WHERE `user_id` = ?', [$ownerId], 0),
            'domains' => (int) Db::val('SELECT COUNT(*) FROM `user_domains` WHERE `user_id` = ?', [$ownerId], 0),
            'members' => (int) Db::val('SELECT COUNT(*) FROM `team_members` WHERE `owner_id` = ?', [$ownerId], 0) + 1,
        ];
    }

    /** 还能不能再加一个。links 只算「建了多少」，删掉不退还，避免刷 */
    public static function canAdd(int $ownerId, string $key): bool
    {
        $limit = self::limit($key);
        if ($limit <= 0) {
            return true;
        }
        return (self::used($ownerId)[$key] ?? 0) < $limit;
    }

    /** 审计。企业版才有界面，但记录不挑版本——降级后历史不能丢 */
    public static function audit(int $ownerId, string $action, int $linkId = 0, string $detail = ''): void
    {
        try {
            Db::insert('link_audit', [
                'user_id'    => $ownerId,
                'actor_id'   => Auth::id(),
                'action'     => substr($action, 0, 32),
                'link_id'    => $linkId,
                'detail'     => mb_substr($detail, 0, 500),
                'ip'         => (new Request())->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // 审计写不进去不能挡业务
        }
    }
}

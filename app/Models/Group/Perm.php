<?php
/**
 * This file is part of the ForkBB <https://forkbb.ru, https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Models\Group;

use ForkBB\Models\Action;
use ForkBB\Models\Forum\Forum;
use ForkBB\Models\Group\Group;
use PDO;
use RuntimeException;

class Perm extends Action
{
    protected array $fields;

    /**
     * Получение таблицы разрешений для раздела
     */
    public function get(Forum $forum): array
    {
        $vars = [
            ':fid' => $forum->id > 0 ? $forum->id : 0,
            ':adm' => FORK_GROUP_ADMIN,
        ];
        $query = 'SELECT g.g_id, fp.read_forum, fp.post_replies, fp.post_topics
            FROM ::groups AS g
            LEFT JOIN ::forum_perms AS fp ON (g.g_id=fp.group_id AND fp.forum_id=?i:fid)
            WHERE g.g_id!=?i:adm
            ORDER BY g.g_id';

        $perms  = $this->c->DB->query($query, $vars)->fetchAll(PDO::FETCH_UNIQUE);
        $result = [];

        foreach ($perms as $gid => $perm) {
            $group  = $this->c->groups->get($gid);
//           $forums = $this->c->ForumManager->init($group);
//           $group->g_read_forum = (int) ($forums->get($forum->id) instanceof Forum);
            $group->g_read_forum = $group->g_read_board;

            foreach ($perm as $field => $value) {
                $group->{'fp_' . $field}  = $value;
                $group->{'set_' . $field} = (1 === $group->{'g_' . $field} && 0 !== $value) || 1 === $value;
                $group->{'def_' . $field} = 1 === $group->{'g_' . $field};
                $group->{'dis_' . $field} = 0 === $group->g_read_board || ('read_forum' !== $field && $forum->redirect_url);
            }

            $result[$gid] = $group;
        }

        $this->fields = \array_keys($perm);

        return $result;
    }

    /**
     * Обновление разрешений для раздела
     */
    public function update(Forum $forum, array $perms): void
    {
        if ($forum->id < 1) {
            throw new RuntimeException('The forum does not have ID');
        }

        foreach ($this->get($forum) as $id => $group) {
            if (0 === $group->g_read_board) {
                continue;
            }

            $row     = [];
            $modDef  = false;
            $modPerm = false;

            foreach ($this->fields as $field) {
                if ($group->{'dis_' . $field}) {
                    $row[$field] = $group->{'set_' . $field} ? 1 : 0;
                    $modDef      = $row[$field] !== $group->{'g_' . $field} ? true : $modDef;

                } else {
                    $row[$field] = empty($perms[$id][$field]) ? 0 : 1;
                    $modDef      = $row[$field] !== $group->{'g_' . $field} ? true : $modDef;
                    $modPerm     = $row[$field] !== (int) $group->{'set_' . $field} ? true : $modPerm;
                }
            }

            if (
                $modDef
                || $modPerm
            ) {
                $vars = [
                    ':gid' => $id,
                    ':fid' => $forum->id,
                ];
                $query = 'DELETE
                    FROM ::forum_perms
                    WHERE group_id=?i:gid AND forum_id=?i:fid';

                $this->c->DB->exec($query, $vars);
            }

            if ($modDef) {
                $vars   = \array_values($row);
                $vars[] = $id;
                $vars[] = $forum->id;
                $list   = \array_keys($row);
                $list[] = 'group_id';
                $list[] = 'forum_id';
                $list2  = \array_fill(0, \count($list), '?i');

                $list   = \implode(', ', $list);
                $list2  = \implode(', ', $list2);
                $query  = "INSERT INTO ::forum_perms ({$list})
                    VALUES ({$list2})";

                $this->c->DB->exec($query, $vars);
            }
        }
    }

    /**
     * Сброс разрешений для раздела
     */
    public function reset(Forum $forum): void
    {
        if ($forum->id < 1) {
            throw new RuntimeException('The forum does not have ID');
        }

        $vars = [
            ':fid' => $forum->id,
        ];
        $query = 'DELETE
            FROM ::forum_perms
            WHERE forum_id=?i:fid';

        $this->c->DB->exec($query, $vars);
    }

    /**
     * Удаление разрешений для группы
     */
    public function delete(Group $group): void
    {
        if ($group->g_id < 1) {
            throw new RuntimeException('The group does not have ID');
        }

        $vars = [
            ':gid' => $group->g_id,
        ];
        $query = 'DELETE
            FROM ::forum_perms
            WHERE group_id=?i:gid';

        $this->c->DB->exec($query, $vars);
    }

    /**
     * Копирование разрешений первой группы во вторую
     */
    public function copy(Group $from, Group $to): void
    {
        if (
            $from->g_id < 1
            || $to->g_id < 1
        ) {
            throw new RuntimeException('The group does not have ID');
        }

        $this->delete($to);

        $vars = [
            ':old' => $from->g_id,
            ':new' => $to->g_id,
        ];
        $query = 'INSERT INTO ::forum_perms (group_id, forum_id, read_forum, post_replies, post_topics)
            SELECT ?i:new, forum_id, read_forum, post_replies, post_topics
            FROM ::forum_perms
            WHERE group_id=?i:old';

        $this->c->DB->exec($query, $vars);
    }
}

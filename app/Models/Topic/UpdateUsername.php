<?php
/**
 * This file is part of the ForkBB <https://forkbb.ru, https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Models\Topic;

use ForkBB\Models\Action;
use ForkBB\Models\User\User;
use RuntimeException;

class UpdateUsername extends Action
{
    /**
     * Обновляет имя пользователя в таблице тем
     */
    public function updateUsername(User $user): void
    {
        if ($user->isGuest) {
            throw new RuntimeException('User expected, not guest');
        }

        $vars = [
            ':id'   => $user->id,
            ':name' => $user->username,
        ];
        $query = 'UPDATE ::topics
            SET poster=?s:name
            WHERE poster_id=?i:id';

        $this->c->DB->exec($query, $vars);

        $query = 'UPDATE ::topics
            SET last_poster=?s:name
            WHERE last_poster_id=?i:id';

        $this->c->DB->exec($query, $vars);

        $query = 'UPDATE ::topics
            SET solution_wa=?s:name
            WHERE solution_wa_id=?i:id';

        $this->c->DB->exec($query, $vars);
    }
}

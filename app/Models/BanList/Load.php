<?php
/**
 * This file is part of the ForkBB <https://forkbb.ru, https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Models\BanList;

use ForkBB\Models\Method;
use ForkBB\Models\BanList\BanList;
use RuntimeException;

class Load extends Method
{
    /**
     * Загружает список банов из БД для модели и кеша
     */
    public function load(): array
    {
        $userList  = [];
        $emailList = [];
        $ipList    = [];
        $banList   = [];
        $first     = 0;
        $query     = 'SELECT b.id, b.username, b.ip, b.email, b.message, b.expire FROM ::bans AS b';
        $stmt      = $this->c->DB->query($query);

        while ($row = $stmt->fetch()) {
            $name = $this->model->trimToNull($row['username'], true);

            if (null !== $name) {
                $userList[$name] = $row['id'];
            }

            $email = $this->model->trimToNull($row['email']);

            if (null !== $email) {
                $email             = $this->c->NormEmail->normalize($email);
                $emailList[$email] = $row['id']; // ???? TODO если домен забанен, то email не добавлять
            }

            $ips = $this->model->trimToNull($row['ip']);

            if (null !== $ips) {
                foreach (\explode(' ', $ips) as $ip) {
                    $list    = &$ipList;
                    $letters = \str_split($this->model->ip2hex($ip));
                    $count   = \count($letters);

                    foreach ($letters as $letter) {
                        if (--$count) {
                            if (! isset($list[$letter])) {
                                $list[$letter] = [];

                            } elseif (! \is_array($list[$letter])) {
                                break;
                            }

                            $list = &$list[$letter];

                        } else {
                            $list[$letter] = $row['id']; // ???? может не перезаписывать предыдущий бан?
                        }
                    }

                    unset($list);
                }
            }

            $message = $this->model->trimToNull($row['message']);
            $expire  = empty($row['expire']) ? null : $row['expire'];

            if (
                null === $message
                && null === $expire
            ) {
                continue;
            }

            $banList[$row['id']] = [
                'message'  => $message,
                'expire'   => $expire,
            ];

            if (
                null !== $expire
                && $expire > 0
                && (
                    0 === $first
                    || $expire < $first
                )
            ) {
                $first = $expire;
            }
        }

        return [
            'banList'     => $banList,
            'userList'    => $userList,
            'emailList'   => $emailList,
            'ipList'      => $ipList,
            'firstExpire' => $first,
        ];
    }
}

<?php
/**
 * This file is part of the ForkBB <https://forkbb.ru, https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Models\Pages;

use ForkBB\Core\Validator;
use ForkBB\Models\Page;
use ForkBB\Models\Forum\Forum;
use InvalidArgumentException;
use function \ForkBB\__;

class Userlist extends Page
{
    /**
     * Возвращает список доступных групп
     */
    protected function getgroupList(): array
    {
        $list = [
            'all' => __('All users'),
        ];

        foreach ($this->c->groups->repository as $group) {
            if (! $group->groupGuest) {
                $list[$group->g_id] = $group->g_title;
            }
        }

        return $list;
    }

    /**
     * Список пользователей
     */
    public function view(array $args, string $method): Page
    {
        $this->c->Lang->load('validator');
        $this->c->Lang->load('userlist');

        $prefix = 'POST' === $method ? 'required|' : '';
        $v = $this->c->Validator->reset()
            ->addRules([
                'sort'  => $prefix . 'string|in:username,registered' . ($this->userRules->showPostCount ? ',num_posts' : ''),
                'dir'   => $prefix . 'string|in:ASC,DESC',
                'group' => $prefix . 'string|in:' . \implode(',', \array_keys($this->groupList)),
                'name'  => $prefix . 'string|min:1|max:190' . ($this->userRules->searchUsers ? '' : '|in:*'),
            ]);

        $error = true;

        if ($v->validation('POST' === $method ? $_POST : $args)) {
            $count = (int) (null === $v->sort)
                   + (int) (null === $v->dir)
                   + (int) (null === $v->group)
                   + (int) (null === $v->name);

            if (
                0 === $count
                || 4 === $count
            ) {
                $error = false;
            }
        }

        if ($error) {
            return $this->c->Message->message('Bad request');
        }

        if ('POST' === $method) {
            return $this->c->Redirect->page('Userlist', $v->getData());
        }

        $filters = [];

        if (\is_numeric($v->group)) {
            $filters['group_id'] = ['=', $v->group];

        } else {
            $filters['group_id'] = ['!=', 0];
        }

        if (null !== $v->name) {
            $filters['username'] = ['LIKE', $v->name];

            if (
                \preg_match('%[\x80-\xFF]%', $v->name)
                && ! $this->c->config->insensitive()
            ) {
                $this->fIswev = [FORK_MESS_INFO, 'The search may be case sensitive'];
            }
        }

        $order  = $v->sort ? [$v->sort => $v->dir] : [];

        $ids    = $this->c->users->filter($filters, $order);
        $number = \count($ids);
        $page   = $args['page'] ?? 1;
        $pages  = (int) \ceil(($number ?: 1) / $this->c->config->i_disp_users);

        if ($page > $pages) {
            return $this->c->Message->message('Not Found', true, 404);
        }

        if ($number) {
            $this->startNum = ($page - 1) * $this->c->config->i_disp_users;
            $ids            = \array_slice($ids, $this->startNum, $this->c->config->i_disp_users);
            $this->userList = $this->c->users->loadByIds($ids);

            $links = [];
            $vars  = ['page' => $page];

            if (4 === $count) {
                $vars['group'] = 'all';
                $vars['name']  = '*';

            } else {
                $vars['group'] = $v->group;
                $vars['name']  = $v->name;
            }

            $this->activeLink = 0;

            foreach (['username', 'num_posts', 'registered'] as $i => $sort) {
                $vars['sort'] = $sort;

                foreach (['ASC', 'DESC'] as $j => $dir) {
                    $vars['dir']        = $dir;
                    $links[$i * 2 + $j] = $this->c->Router->link('Userlist', $vars);

                    if (
                        $v->sort === $sort
                        && $v->dir === $dir
                    ) {
                        $this->activeLink = $i * 2 + $j;
                    }
                }
            }

            $this->links = $links;

        } else {
            $this->startNum = 0;
            $this->userList = null;
            $this->links    = [null, null, null, null, null, null];
            $this->fIswev   = [FORK_MESS_INFO, 'No users found'];
        }

        $this->identifier   = 'userlist';
        $this->fIndex       = self::FI_USERS;
        $this->nameTpl      = 'userlist';
        $this->onlinePos    = 'userlist';
        $this->canonical    = $this->c->Router->link('Userlist', $args);
        $this->robots       = 'noindex';
        $this->crumbs       = $this->crumbs(
            [
                $this->c->Router->link('Userlist'),
                'User list',
            ]
        );
        $this->pagination   = $this->c->Func->paginate($pages, $page, 'Userlist', $args);
        $this->form         = $this->formUserlist($v);

        return $this;
    }

    /**
     * Подготавливает массив данных для формы
     */
    protected function formUserlist(Validator $v): array
    {
        $form = [
            'action' => $this->c->Router->link('Userlist'),
            'hidden' => [],
            'sets'   => [],
            'btns'   => [
                'submit' => [
                    'type'  => 'submit',
                    'value' => __($this->userRules->searchUsers ? 'Search btn' : 'Submit'),
                ],
            ],
        ];

        $fields = [];

        if ($this->userRules->searchUsers) {
            $fields['name'] = [
                'class'     => ['w0'],
                'type'      => 'text',
                'maxlength' => '190',
                'value'     => $v->name ?: '*',
                'caption'   => 'Username',
                'help'      => 'User search info',
                'required'  => true,
            ];

        } else {
            $form['hidden']['name'] = '*';
        }

        $fields['group'] = [
            'class'   => ['w4'],
            'type'    => 'select',
            'options' => $this->groupList,
            'value'   => $v->group,
            'caption' => 'User group',
        ];
        $fields['sort'] = [
            'class'   => ['w4'],
            'type'    => 'select',
            'options' => [
                ['username', __('Sort by name')],
                ['num_posts', __('Sort by number'), $this->userRules->showPostCount ? null : true],
                ['registered', __('Sort by date')],
            ],
            'value'   => $v->sort,
            'caption' => 'Sort users by',
        ];
        $fields['dir'] = [
            'class'   => ['w4'],
            'type'    => 'radio',
            'value'   => $v->dir ?: 'ASC',
            'values'  => [
                'ASC'  => __('Ascending'),
                'DESC' => __('Descending'),
            ],
            'caption' => 'User sort order',
        ];
        $form['sets']['users'] = ['fields' => $fields];

        return $form;
    }
}

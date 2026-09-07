<?php

/**
 *
 * Forum Fortress. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
 * @license license.txt GNU General Public License, version 2 or later (GPL-2.0-or-later)
 *
 */

namespace forumfortress\protect\acp;

if (!defined('IN_PHPBB'))
{
	exit;
}

/**
 * ACP module definition.
 */
class main_info
{
	public function module()
	{
		return [
			'filename' => 'forumfortress\protect\acp\main_module',
			'title' => 'ACP_FORUMFORTRESS_TITLE',
			'modes' => [
				'settings' => [
					'title' => 'ACP_FORUMFORTRESS_SETTINGS',
					'auth' => 'ext_forumfortress/protect && acl_a_board',
					'cat' => ['ACP_FORUMFORTRESS_TITLE'],
				],
			],
		];
	}
}

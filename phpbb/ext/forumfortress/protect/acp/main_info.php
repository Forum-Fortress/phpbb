<?php

namespace forumfortress\protect\acp;

if (!defined('IN_PHPBB'))
{
	exit;
}

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

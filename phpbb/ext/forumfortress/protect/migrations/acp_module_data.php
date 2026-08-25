<?php

namespace forumfortress\protect\migrations;

/**
 * Shared ACP module install/remove steps for migrations.
 *
 * Register a parent category under ACP_CAT_DOT_MODS, then the settings mode
 * underneath (same two-step pattern as phpbbde/tou). A flat "Settings" entry
 * under Extensions is easy to miss and does not match the documented menu path.
 *
 * Use explicit module rows instead of automatic module.add/module.remove calls.
 * phpBB's module finder only includes enabled extensions, while these steps run
 * while an extension is being enabled, disabled, or purged.
 *
 * Children must be removed before parents; phpBB throws CANNOT_REMOVE_MODULE otherwise.
 */
class acp_module_data
{
	public static function add_modules()
	{
		return [
			['module.add', [
				'acp',
				'ACP_CAT_DOT_MODS',
				'ACP_FORUMFORTRESS_TITLE',
			]],
			['module.add', [
				'acp',
				'ACP_FORUMFORTRESS_TITLE',
				[
					'module_basename' => 'forumfortress\\protect\\acp\\main_module',
					'module_langname' => 'ACP_FORUMFORTRESS_SETTINGS',
					'module_mode' => 'settings',
					'module_auth' => 'ext_forumfortress/protect && acl_a_board',
				],
			]],
		];
	}

	public static function remove_legacy()
	{
		return [
			['module.remove', [
				'acp',
				'ACP_FORUMFORTRESS_TITLE',
				'ACP_FORUMFORTRESS_SETTINGS',
			]],
			['module.remove', [
				'acp',
				'ACP_CAT_DOT_MODS',
				'ACP_FORUMFORTRESS_SETTINGS',
			]],
			['module.remove', [
				'acp',
				'ACP_CAT_DOT_MODS',
				'ACP_FORUMFORTRESS_TITLE',
			]],
		];
	}

	public static function remove_all()
	{
		return self::remove_legacy();
	}
}

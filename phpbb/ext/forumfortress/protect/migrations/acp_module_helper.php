<?php

namespace forumfortress\protect\migrations;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Idempotent ACP module install/remove via the phpBB module migration tool.
 */
class acp_module_helper
{
	/**
	 * Remove legacy/duplicate entries, then ensure the canonical parent + settings pair.
	 */
	public static function reinstall(ContainerInterface $container): void
	{
		$tool = $container->get('migrator.tool.module');
		$module_manager = $container->get('module.manager');

		foreach (acp_module_data::remove_legacy() as $step)
		{
			self::remove_step($tool, $step);
		}

		if (!$tool->exists('acp', 'ACP_CAT_DOT_MODS', 'ACP_FORUMFORTRESS_TITLE'))
		{
			$tool->add('acp', 'ACP_CAT_DOT_MODS', 'ACP_FORUMFORTRESS_TITLE');
		}

		if (!$tool->exists('acp', 'ACP_FORUMFORTRESS_TITLE', 'ACP_FORUMFORTRESS_SETTINGS'))
		{
			self::add_settings_module($container);
		}

		$module_manager->remove_cache_file('acp');
	}

	/**
	 * True when the Extensions submenu and settings mode are registered.
	 */
	public static function modules_complete(ContainerInterface $container): bool
	{
		$tool = $container->get('migrator.tool.module');

		return $tool->exists('acp', 'ACP_CAT_DOT_MODS', 'ACP_FORUMFORTRESS_TITLE')
			&& $tool->exists('acp', 'ACP_FORUMFORTRESS_TITLE', 'ACP_FORUMFORTRESS_SETTINGS');
	}

	protected static function remove_step($tool, array $step): void
	{
		if ($step[0] !== 'module.remove')
		{
			return;
		}

		$args = $step[1];
		try
		{
			$tool->remove($args[0], $args[1], $args[2] ?? '');
		}
		catch (\phpbb\module\exception\module_exception $e)
		{
			// Ignore missing or already-removed modules during idempotent reinstall.
		}
	}

	protected static function add_settings_module(ContainerInterface $container): void
	{
		$db = $container->get('dbal.conn');
		$module_manager = $container->get('module.manager');
		$modules_table = $container->getParameter('tables.modules');
		$parent_id = self::lookup_parent_id($db, $modules_table);

		if (!$parent_id)
		{
			return;
		}

		$module_data = [
			'module_enabled' => 1,
			'module_display' => 1,
			'module_basename' => 'forumfortress\\protect\\acp\\main_module',
			'module_class' => 'acp',
			'parent_id' => $parent_id,
			'module_langname' => 'ACP_FORUMFORTRESS_SETTINGS',
			'module_mode' => 'settings',
			'module_auth' => 'ext_forumfortress/protect && acl_a_board',
		];

		$module_manager->update_module_data($module_data);
	}

	protected static function lookup_parent_id($db, string $modules_table): int
	{
		$sql = 'SELECT module_id
			FROM ' . $modules_table . "
			WHERE module_class = 'acp'
				AND module_langname = 'ACP_CAT_DOT_MODS'
				AND parent_id = 0";
		$result = $db->sql_query_limit($sql, 1);
		$dot_mods_id = (int) $db->sql_fetchfield('module_id');
		$db->sql_freeresult($result);

		if (!$dot_mods_id)
		{
			return 0;
		}

		$sql = 'SELECT module_id
			FROM ' . $modules_table . '
			WHERE module_class = \'acp\'
				AND parent_id = ' . $dot_mods_id . "
				AND module_langname = 'ACP_FORUMFORTRESS_TITLE'
			ORDER BY module_id DESC";
		$result = $db->sql_query_limit($sql, 1);
		$parent_id = (int) $db->sql_fetchfield('module_id');
		$db->sql_freeresult($result);

		return $parent_id;
	}
}

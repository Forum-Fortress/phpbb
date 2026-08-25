<?php

namespace forumfortress\protect\migrations;

class v_1_0_19_acp_module_path extends \phpbb\db\migration\container_aware_migration
{
	public static function depends_on()
	{
		return ['\\forumfortress\\protect\\migrations\\v_1_0_18_state_text'];
	}

	public function effectively_installed()
	{
		return isset($this->config['ffprotect_acp_module_path_v19']);
	}

	public function update_data()
	{
		return [
			['custom', [[$this, 'fix_acp_module_path']]],
			['config.add', ['ffprotect_acp_module_path_v19', 1]],
		];
	}

	public function fix_acp_module_path()
	{
		$db = $this->container->get('dbal.conn');
		$modules_table = $this->container->getParameter('tables.modules');
		$old = $db->sql_escape('\\forumfortress\\protect\\acp\\main_module');
		$new = $db->sql_escape('forumfortress\\protect\\acp\\main_module');
		$db->sql_query("UPDATE {$modules_table} SET module_basename = '{$new}' WHERE module_class = 'acp' AND module_basename = '{$old}'");
		$this->container->get('module.manager')->remove_cache_file('acp');
	}

	public function revert_data()
	{
		return [
			['config.remove', ['ffprotect_acp_module_path_v19']],
		];
	}
}

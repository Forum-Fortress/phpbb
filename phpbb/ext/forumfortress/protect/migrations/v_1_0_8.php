<?php

namespace forumfortress\protect\migrations;

class v_1_0_8 extends \phpbb\db\migration\container_aware_migration
{
	public function effectively_installed()
	{
		return isset($this->config['ffprotect_acp_menu_v108'])
			&& acp_module_helper::modules_complete($this->container);
	}

	static public function depends_on()
	{
		return ['\forumfortress\protect\migrations\v_1_0_7'];
	}

	public function update_data()
	{
		return [
			['custom', [[$this, 'ensure_acp_modules']]],
			['config.add', ['ffprotect_acp_menu_v108', 1]],
		];
	}

	public function ensure_acp_modules()
	{
		acp_module_helper::reinstall($this->container);
	}

	public function revert_data()
	{
		return array_merge(
			acp_module_data::remove_all(),
			[
				['config.remove', ['ffprotect_acp_menu_v108']],
			]
		);
	}
}

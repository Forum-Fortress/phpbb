<?php

namespace forumfortress\protect\migrations;

/**
 * Legacy stub — module teardown consolidated into v_1_0_7.
 */
class v_1_0_0_alpha16 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['ffprotect_acp_module_basename_v1']);
	}

	static public function depends_on()
	{
		return ['\forumfortress\protect\migrations\v_1_0_0_alpha15'];
	}

	public function update_data()
	{
		return [];
	}

	public function revert_data()
	{
		return array_merge(
			acp_module_data::remove_all(),
			[
				['config.remove', ['ffprotect_acp_module_basename_v1']],
			]
		);
	}
}

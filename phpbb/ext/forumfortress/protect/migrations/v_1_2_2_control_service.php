<?php

namespace forumfortress\protect\migrations;

class v_1_2_2_control_service extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\\forumfortress\\protect\\migrations\\v_1_0_20_api_region'];
	}

	public function effectively_installed()
	{
		return !isset($this->config['ffprotect_control_base_url']);
	}

	public function update_data()
	{
		return [
			['config.remove', ['ffprotect_control_base_url']],
		];
	}
}

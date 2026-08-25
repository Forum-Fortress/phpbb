<?php

namespace forumfortress\protect\migrations;

class v_1_0_0_alpha14 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return true;
	}

	static public function depends_on()
	{
		return ['\forumfortress\protect\migrations\v_1_0_0_alpha13'];
	}

	public function update_data()
	{
		return [];
	}
}

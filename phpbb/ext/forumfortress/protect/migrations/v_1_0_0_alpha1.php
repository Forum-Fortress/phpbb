<?php

namespace forumfortress\protect\migrations;

/**
 * Legacy alpha migration stub — kept so boards that already ran this class name stay valid.
 */
class v_1_0_0_alpha1 extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return true;
	}

	static public function depends_on()
	{
		return ['\forumfortress\protect\migrations\v_1_0_0'];
	}

	public function update_data()
	{
		return [];
	}
}

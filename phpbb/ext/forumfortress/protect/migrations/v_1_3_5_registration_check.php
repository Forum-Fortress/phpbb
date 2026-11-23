<?php
/** Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress; GPL-2.0-or-later. */

namespace forumfortress\protect\migrations;

class v_1_3_5_registration_check extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\\forumfortress\\protect\\migrations\\v_1_3_4_repeat_cleanup_gate'];
	}

	public function update_schema()
	{
		return ['add_columns' => [
			$this->table_prefix . 'users' => ['user_ff_reg_check_id' => ['VCHAR:32', '']],
		]];
	}

	public function revert_schema()
	{
		return ['drop_columns' => [
			$this->table_prefix . 'users' => ['user_ff_reg_check_id'],
		]];
	}
}

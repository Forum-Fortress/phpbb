<?php
/** Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress; GPL-2.0-or-later. */
namespace forumfortress\protect\migrations;
class v_1_3_4_repeat_cleanup_gate extends \phpbb\db\migration\migration
{
	public static function depends_on() { return ['\\forumfortress\\protect\\migrations\\v_1_3_3_repeat_cleanup']; }
	public function update_schema() { return ['add_columns' => [$this->table_prefix.'ff_repeat_events' => ['cleanup_eligible' => ['BOOL', 0]]]]; }
	public function update_data() { return []; }
	public function revert_schema() { return ['drop_columns' => [$this->table_prefix.'ff_repeat_events' => ['cleanup_eligible']]]; }
}

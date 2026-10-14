<?php
/** Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress; GPL-2.0-or-later. */
namespace forumfortress\protect\migrations;
class v_1_3_3_repeat_cleanup extends \phpbb\db\migration\migration
{
	public static function depends_on() { return ['\\forumfortress\\protect\\migrations\\v_1_3_2_repeat']; }
	public function update_schema() {
		return [
			'add_columns' => [$this->table_prefix.'ff_repeat_state' => [
				'cleanup_pending' => ['BOOL', 0], 'cleanup_status' => ['VCHAR:16', 'not_requested'],
				'cleanup_count' => ['UINT', 0], 'cleanup_skipped' => ['UINT', 0], 'cleanup_at' => ['TIMESTAMP', 0],
			]],
			'add_tables' => [$this->table_prefix.'ff_repeat_cleanup_receipt' => [
				'COLUMNS' => ['post_id' => ['UINT', 0], 'uid' => ['UINT', 0], 'status' => ['VCHAR:16', 'cleaned'], 'created_at' => ['TIMESTAMP', 0]],
				'PRIMARY_KEY' => 'post_id', 'KEYS' => ['member' => ['INDEX', ['uid', 'created_at']]],
			]],
		];
	}
	public function update_data() { return [['config.add', ['ffprotect_repeat_cleanup', 0]], ['custom', [[$this, 'prepare_tables']]]]; }
	public function prepare_tables() { if (strpos($this->db->get_sql_layer(), 'mysql') === 0) { $this->sql_query('ALTER TABLE '.$this->table_prefix.'ff_repeat_cleanup_receipt ENGINE=InnoDB'); } }
	public function revert_data() { return [['config.remove', ['ffprotect_repeat_cleanup']]]; }
	public function revert_schema() { return ['drop_tables' => [$this->table_prefix.'ff_repeat_cleanup_receipt'], 'drop_columns' => [$this->table_prefix.'ff_repeat_state' => ['cleanup_pending', 'cleanup_status', 'cleanup_count', 'cleanup_skipped', 'cleanup_at']]]; }
}

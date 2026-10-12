<?php
/** Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress; GPL-2.0-or-later. */
namespace forumfortress\protect\migrations;
class v_1_3_2_repeat extends \phpbb\db\migration\migration
{
	public static function depends_on() { return ['\\forumfortress\\protect\\migrations\\v_1_2_2_control_service']; }
	public function update_schema() {
		return ['add_tables'=>[
			$this->table_prefix.'ff_repeat_state'=>['COLUMNS'=>[
				'uid'=>['UINT',0], 'pending'=>['BOOL',0], 'touched_at'=>['TIMESTAMP',0], 'attempted_at'=>['TIMESTAMP',0], 'ban_id'=>['UINT',0]],
				'PRIMARY_KEY'=>'uid','KEYS'=>['pending'=>['INDEX',['pending','touched_at']]]],
			$this->table_prefix.'ff_repeat_events'=>['COLUMNS'=>[
				'uid'=>['UINT',0], 'body_hash'=>['VCHAR:64',''], 'reference_hash'=>['VCHAR:64',''], 'observed_at'=>['TIMESTAMP',0], 'consumed'=>['BOOL',0]],
				'KEYS'=>['body'=>['UNIQUE',['uid','body_hash']], 'reference'=>['UNIQUE',['uid','reference_hash']], 'observed'=>['INDEX','observed_at']]],
		]];
	}
	public function update_data() { return [['config.add',['ffprotect_repeat_ban',0]],['config.add',['ffprotect_repeat_last',0]],['custom',[[$this,'prepare_tables']]]]; }
	public function prepare_tables() {
		if (strpos($this->db->get_sql_layer(),'mysql')!==0) { return; }
		foreach (['ff_repeat_state','ff_repeat_events'] as $suffix) { $this->sql_query('ALTER TABLE '.$this->table_prefix.$suffix.' ENGINE=InnoDB'); }
		$this->sql_query('INSERT IGNORE INTO '.$this->table_prefix.'ff_repeat_state (uid) VALUES (0)');
	}
	public function revert_data() { return [['config.remove',['ffprotect_repeat_ban']],['config.remove',['ffprotect_repeat_last']]]; }
	public function revert_schema() { return ['drop_tables'=>[$this->table_prefix.'ff_repeat_events',$this->table_prefix.'ff_repeat_state']]; }
}

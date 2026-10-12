<?php
/** Copyright (c) 2026 Marscastle Ltd trading as Forum Fortress; GPL-2.0-or-later. */
namespace forumfortress\protect\service;

/** Evidence is persisted at garbage_collection; only the separate native cron task bans. */
class repeat_offenders
{
	private $db;
	private string $prefix;
	private string $root;
	private string $php_ext;
	private array $staged = [];
	public const WINDOW = 86400;
	public const RETENTION = 604800;

	public function __construct(\phpbb\db\driver\driver_interface $db, string $prefix, string $root, string $php_ext)
	{
		$this->db = $db; $this->prefix = $prefix; $this->root = $root; $this->php_ext = $php_ext;
	}
	private function q(string $sql)
	{
		$old = $this->db->return_on_error;
		$this->db->sql_return_on_error(true);
		try {
			$result = $this->db->sql_query($sql);
			if ($result === false) { throw new \RuntimeException('Repeat enforcement database operation failed'); }
			return $result;
		} finally { $this->db->sql_return_on_error($old); }
	}
	private function tx(string $mode): void
	{
		$old = $this->db->return_on_error;
		$this->db->sql_return_on_error(true);
		try {
			if ($this->db->sql_transaction($mode) === false
				|| ($mode === 'begin' && !$this->db->get_transaction())
				|| ($mode === 'commit' && $this->db->get_transaction())) { throw new \RuntimeException('Repeat enforcement transaction failed'); }
		} finally { $this->db->sql_return_on_error($old); }
	}
	private function rollback(): void
	{
		if ($this->db->get_transaction()) { try { $this->tx('rollback'); } catch (\Throwable $e) {} }
	}
	private function rows(string $sql): array
	{
		$result = $this->q($sql); $rows = [];
		while ($row = $this->db->sql_fetchrow($result)) { $rows[] = $row; }
		$this->db->sql_freeresult($result); return $rows;
	}
	private function scalar(string $sql): int { $rows = $this->rows($sql); return (int) (reset($rows[0]) ?: 0); }
	private function idle(): bool
	{
		return !$this->db->get_transaction();
	}
	public function available(): bool
	{
		try {
			if (!$this->idle() || strpos($this->db->get_sql_layer(), 'mysql') !== 0
				|| !is_file($this->root . 'includes/functions_user.' . $this->php_ext)
				|| !is_file(__DIR__ . '/../cron/task/repeat_offenders.php')
				|| $this->scalar('SELECT @@autocommit') !== 1) { return false; }
			$names = [$this->prefix.'ff_repeat_events', $this->prefix.'ff_repeat_state', USERS_TABLE, BANLIST_TABLE,
				SESSIONS_TABLE, SESSIONS_KEYS_TABLE, LOG_TABLE, CONFIG_TABLE, EXT_TABLE, GROUPS_TABLE,
				USER_GROUP_TABLE, ACL_USERS_TABLE, ACL_GROUPS_TABLE, ACL_OPTIONS_TABLE, ACL_ROLES_DATA_TABLE];
			$rows = $this->rows('SHOW TABLE STATUS WHERE ' . $this->db->sql_in_set('Name', $names));
			if (count($rows) !== count($names)) { return false; }
			foreach ($rows as $row) { if (strtoupper((string) $row['Engine']) !== 'INNODB') { return false; } }
			return $this->scalar('SELECT COUNT(*) FROM '.$this->prefix.'ff_repeat_state WHERE uid=0') === 1;
		} catch (\Throwable $e) { return false; }
	}
	private function enabled(bool $lock = false): bool
	{
		$rows = $this->rows('SELECT config_name, config_value FROM '.CONFIG_TABLE." WHERE config_name IN ('ffprotect_enabled','ffprotect_repeat_ban')".($lock ? ' FOR UPDATE' : ''));
		$values = array_column($rows, 'config_value', 'config_name');
		$ext = $this->rows('SELECT ext_active FROM '.EXT_TABLE." WHERE ext_name='forumfortress/protect'".($lock ? ' FOR UPDATE' : ''));
		return ($values['ffprotect_enabled'] ?? '') === '1' && ($values['ffprotect_repeat_ban'] ?? '') === '1'
			&& (int) ($ext[0]['ext_active'] ?? 0) === 1;
	}
	public static function reference(?array $response): ?string
	{
		if (!$response || ($response['escalation_eligible'] ?? null) !== true || !is_string($response['decision'] ?? null)
			|| strtolower(trim($response['decision'])) !== 'block') { return null; }
		$value = $response['decision_reference'] ?? null;
		if (is_string($value) && trim($value) !== '' && strlen($value) <= 256) { return hash('sha256', 'reference:'.trim($value)); }
		$id = $response['decision_id'] ?? null;
		if ((is_int($id) || is_string($id)) && ctype_digit((string)$id) && strlen((string)$id) <= 32 && ltrim((string)$id, '0') !== '') { return hash('sha256', 'decision:'.ltrim((string)$id, '0')); }
		return null;
	}
	public function stage(int $uid, int $actor, string $body, ?array $response): void
	{
		$ref = self::reference($response);
		if ($uid <= 1 || $uid !== $actor || !$ref || trim($body) === '' || count($this->staged) >= 16) { return; }
		try { if ($this->enabled()) { $this->staged[] = [$uid, hash('sha256', trim($body)), $ref]; } } catch (\Throwable $e) {}
	}
	/** Fresh role and account rows are locked until the evidence/action transaction commits. */
	private function eligible(int $uid): ?array
	{
		$rows = $this->rows('SELECT * FROM '.USERS_TABLE.' WHERE user_id='.$uid.' FOR UPDATE'); $u = $rows[0] ?? null;
		$now = time();
		if (!$u || $uid <= 1 || (int) $u['user_type'] !== USER_NORMAL || (int) $u['user_posts'] > 10
			|| (int) $u['user_regdate'] <= 0 || (int) $u['user_regdate'] > $now || $now-(int)$u['user_regdate'] > self::RETENTION) { return null; }
		if ($this->rows('SELECT ban_id FROM '.BANLIST_TABLE.' WHERE ban_userid='.$uid.' FOR UPDATE')) { return null; }
		$memberships = $this->rows('SELECT group_id, user_pending FROM '.USER_GROUP_TABLE.' WHERE user_id='.$uid.' FOR UPDATE');
		$gids = [(int) $u['group_id']];
		foreach ($memberships as $m) { $gids[] = (int) $m['group_id']; }
		$gids = array_unique($gids);
		$groups = $this->rows('SELECT group_id, group_name, group_type FROM '.GROUPS_TABLE.' WHERE '.$this->db->sql_in_set('group_id', $gids).' FOR UPDATE');
		if (count($groups) !== count($gids)) { return null; }
		foreach ($groups as $g) { if ((int) $g['group_type'] !== GROUP_SPECIAL || !in_array($g['group_name'], ['REGISTERED','NEWLY_REGISTERED'], true)) { return null; } }
		$options = $this->rows('SELECT auth_option_id FROM '.ACL_OPTIONS_TABLE." WHERE auth_option LIKE 'a\\_%' OR auth_option LIKE 'm\\_%' FOR UPDATE");
		$staff = array_column($options, 'auth_option_id');
		$grants = $this->rows('SELECT auth_option_id, auth_setting, auth_role_id FROM '.ACL_USERS_TABLE.' WHERE user_id='.$uid.' FOR UPDATE');
		$grants = array_merge($grants, $this->rows('SELECT auth_option_id, auth_setting, auth_role_id FROM '.ACL_GROUPS_TABLE.' WHERE '.$this->db->sql_in_set('group_id', $gids).' FOR UPDATE'));
		$roles = [];
		foreach ($grants as $grant) {
			if ((int)$grant['auth_setting'] === ACL_YES && in_array($grant['auth_option_id'], $staff)) { return null; }
			if ((int)$grant['auth_role_id'] > 0) { $roles[] = (int)$grant['auth_role_id']; }
		}
		if ($roles) {
			foreach ($this->rows('SELECT auth_option_id, auth_setting FROM '.ACL_ROLES_DATA_TABLE.' WHERE '.$this->db->sql_in_set('role_id', array_unique($roles)).' FOR UPDATE') as $grant) {
				if ((int)$grant['auth_setting'] === ACL_YES && in_array($grant['auth_option_id'], $staff)) { return null; }
			}
		}
		return $u;
	}
	private function mutex(): void { $this->rows('SELECT uid FROM '.$this->prefix.'ff_repeat_state WHERE uid=0 FOR UPDATE'); }
	private function state(int $uid): array
	{
		$table=$this->prefix.'ff_repeat_state';
		$this->q("INSERT INTO $table (uid,touched_at) VALUES ($uid,".time().') ON DUPLICATE KEY UPDATE uid=VALUES(uid)');
		return $this->rows("SELECT * FROM $table WHERE uid=$uid FOR UPDATE")[0];
	}
	private function consume(int $uid): void
	{
		$this->q('UPDATE '.$this->prefix.'ff_repeat_state SET pending=0 WHERE uid='.$uid);
		$this->q('UPDATE '.$this->prefix.'ff_repeat_events SET consumed=1 WHERE uid='.$uid);
	}
	public function flush(): void
	{
		$staged=$this->staged; $this->staged=[];
		if (!$staged || !$this->available()) { return; }
		foreach ($staged as [$uid,$body,$ref]) {
			try {
				$this->tx('begin'); $this->mutex();
				if (!$this->enabled(true) || !$this->eligible($uid)
					|| $this->scalar('SELECT COUNT(*) FROM '.$this->prefix.'ff_repeat_state') >= 100000) { $this->tx('rollback'); continue; }
				$state=$this->state($uid);
				if ((int)$state['attempted_at']) { $this->tx('rollback'); continue; }
				$events=$this->prefix.'ff_repeat_events'; $now=time();
				$count=$this->scalar("SELECT COUNT(*) FROM $events WHERE uid=$uid");
				if ($count>=128 || $this->scalar("SELECT COUNT(*) FROM $events")>=50000) { $this->consume($uid); $this->tx('commit'); continue; }
				$recent=$this->scalar("SELECT COUNT(*) FROM $events WHERE uid=$uid AND consumed=0 AND observed_at>=".($now-self::WINDOW));
				if ($recent<2) { $this->q("INSERT IGNORE INTO $events (uid,body_hash,reference_hash,observed_at) VALUES ($uid,'$body','$ref',$now)"); }
				$recent=$this->scalar("SELECT COUNT(*) FROM $events WHERE uid=$uid AND consumed=0 AND observed_at>=".($now-self::WINDOW));
				$this->q('UPDATE '.$this->prefix.'ff_repeat_state SET touched_at='.$now.', pending='.(int)($recent>=2).' WHERE uid='.$uid);
				$this->tx('commit');
			} catch (\Throwable $e) { $this->rollback(); }
		}
	}
	/** Typed boundary: account changes only originate in the native cron task. */
	public function run(\forumfortress\protect\cron\task\repeat_offenders $task): void
	{
		if (!$this->available()) { return; }
		try {
			$this->prune();
			$rows=$this->rows('SELECT uid FROM '.$this->prefix.'ff_repeat_state WHERE pending=1 AND attempted_at=0 ORDER BY touched_at LIMIT 50');
			foreach ($rows as $row) { $this->apply((int)$row['uid']); }
		} catch (\Throwable $e) { $this->rollback(); }
	}
	private function apply(int $uid): void
	{
		if (!$this->available()) { return; }
		global $user;
		$original=$user;
		try {
			$this->tx('begin'); $this->mutex(); $state=$this->state($uid);
			if (!(int)$state['pending'] || (int)$state['attempted_at']) { $this->tx('rollback'); return; }
			$u=$this->eligible($uid);
			$count=$this->scalar('SELECT COUNT(*) FROM '.$this->prefix.'ff_repeat_events WHERE uid='.$uid.' AND consumed=0 AND observed_at>='.(time()-self::WINDOW));
			if (!$u || !$this->enabled(true) || $count<2) { $this->consume($uid); $this->tx('commit'); return; }
			// Core refuses self-bans: run as the native anonymous/system actor, preserving the request's user object.
			$system=$this->rows('SELECT * FROM '.USERS_TABLE.' WHERE user_id='.ANONYMOUS)[0];
			$user=clone $original; $user->data=$system;
			include_once $this->root.'includes/functions_user.'.$this->php_ext;
			$old=$this->db->return_on_error; $this->db->sql_return_on_error(true);
			try { $ok=\user_ban('user', [$u['username']], 10080, '', 0, 'Forum Fortress: two certified spam blocks', 'Forum Fortress: temporary seven-day ban');
				$failed=$this->db->get_sql_error_triggered();
			} finally { $this->db->sql_return_on_error($old); }
			$ban=$this->rows('SELECT ban_id,ban_start,ban_end,ban_exclude FROM '.BANLIST_TABLE.' WHERE ban_userid='.$uid);
			if (!$ok || $failed || count($ban)!==1 || (int)$ban[0]['ban_exclude']!==0 || (int)$ban[0]['ban_end']-(int)$ban[0]['ban_start']!==self::RETENTION) { throw new \RuntimeException('Native ban was not confirmed'); }
			$this->q('UPDATE '.$this->prefix.'ff_repeat_state SET attempted_at='.time().', ban_id='.(int)$ban[0]['ban_id'].' WHERE uid='.$uid);
			$this->consume($uid); $this->tx('commit');
		} catch (\Throwable $e) {
			// All native write tables were verified as InnoDB. Rollback also undoes a partial native ban and its receipt.
			$this->rollback();
		} finally { $user=$original; }
	}
	public function cancel_pending(): void
	{
		// Cancelling private evidence must still work when a native ban dependency is unavailable.
		if (!$this->idle() || strpos($this->db->get_sql_layer(), 'mysql') !== 0) { return; }
		try { $this->tx('begin'); $this->mutex();
			$this->q('UPDATE '.$this->prefix.'ff_repeat_state SET pending=0');
			$this->q('UPDATE '.$this->prefix.'ff_repeat_events SET consumed=1');
			$this->tx('commit');
		} catch (\Throwable $e) { $this->rollback(); }
	}
	private function prune(): void
	{
		$this->tx('begin'); $this->mutex();
		$this->q('DELETE FROM '.$this->prefix.'ff_repeat_events WHERE observed_at<'.(time()-self::RETENTION).' LIMIT 500');
		$rows=$this->rows('SELECT s.uid FROM '.$this->prefix.'ff_repeat_state s LEFT JOIN '.USERS_TABLE.' u ON u.user_id=s.uid WHERE s.uid>1 AND (u.user_id IS NULL OR (s.attempted_at=0 AND s.pending=0 AND s.touched_at<'.(time()-self::RETENTION).')) LIMIT 500');
		if ($rows) { $ids=array_column($rows,'uid'); $this->q('DELETE FROM '.$this->prefix.'ff_repeat_state WHERE '.$this->db->sql_in_set('uid',$ids));
			$this->q('DELETE FROM '.$this->prefix.'ff_repeat_events WHERE '.$this->db->sql_in_set('uid',$ids)); }
		$this->tx('commit');
	}
	public function recent(): array
	{
		try { if ($this->available()) { return $this->rows('SELECT uid,attempted_at,ban_id FROM '.$this->prefix.'ff_repeat_state WHERE attempted_at>0 ORDER BY attempted_at DESC LIMIT 15'); } } catch (\Throwable $e) {}
		return [];
	}
}

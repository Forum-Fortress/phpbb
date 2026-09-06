<?php

/**
 *
 * Forum Fortress. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
 * @license license.txt GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace forumfortress\protect\service;

use phpbb\config\config;
use phpbb\config\db_text;
use phpbb\content_visibility;
use phpbb\db\driver\driver_interface;

use function is_array;
use function json_decode;
use function json_encode;
use function time;

/**
 * FFTimeout: queue timed-out checks for moderation sync recovery (plan-agnostic on control plane).
 */
/**
 * Persists and recovers checks that timed out under fail-open policy.
 */
class timeout_queue
{
	public const TIMEOUT_TAG = 'FFTimeout';
	public const SOURCE = 'ff_timeout';
	public const ENTRY_TTL_SECONDS = 604800;
	public const MAX_PENDING_ENTRIES = 100;
	public const MAX_CHECK_PAYLOAD_BYTES = 65536;

	protected config $config;
	protected db_text $config_text;
	protected driver_interface $db;
	protected content_visibility $content_visibility;
	protected string $posts_table;
	protected string $topics_table;

	public function __construct(
		config $config,
		db_text $config_text,
		driver_interface $db,
		content_visibility $content_visibility,
		string $posts_table,
		string $topics_table
	)
	{
		$this->config = $config;
		$this->config_text = $config_text;
		$this->db = $db;
		$this->content_visibility = $content_visibility;
		$this->posts_table = $posts_table;
		$this->topics_table = $topics_table;
	}

	public function enqueue(string $endpoint, array $check_payload, array $context = []): void
	{
		$pending = $this->load_pending();
		$remote_type = (string) ($context['remote_content_type'] ?? 'pending_check');
		$remote_id = (string) ($context['remote_content_id'] ?? '');
		if (!in_array($remote_type, ['thread', 'post', 'user'], true) || !preg_match('/^[1-9][0-9]*$/D', $remote_id))
		{
			// Recovery items must reference a persisted phpBB object, never a provisional hash.
			return;
		}
		$payload_json = json_encode($check_payload);
		if (!is_string($payload_json) || strlen($payload_json) > self::MAX_CHECK_PAYLOAD_BYTES)
		{
			return;
		}

		$now = time();
		$queue_id = $this->queue_id($remote_type, $remote_id);
		$pending[$queue_id] = [
			'queue_id' => $queue_id,
			'remote_content_type' => $remote_type,
			'remote_content_id' => $remote_id,
			'endpoint' => $endpoint,
			'check_payload' => $check_payload,
			'username' => (string) ($context['username'] ?? $check_payload['username'] ?? ''),
			'title' => (string) ($context['title'] ?? 'Timed-out protection check'),
			'excerpt' => (string) ($context['excerpt'] ?? ''),
			'queued_at' => $now,
			'expires_at' => $now + self::ENTRY_TTL_SECONDS,
		];
		$this->save_pending($this->prune_pending($pending));
	}

	public function sync_items(): array
	{
		$pending = $this->load_pending();
		$items = [];
		foreach ($pending as $entry)
		{
			if (!is_array($entry))
			{
				continue;
			}
			$check_payload = $entry['check_payload'] ?? [];
			if (!is_array($check_payload))
			{
				continue;
			}
			$items[] = [
				'remote_content_type' => (string) ($entry['remote_content_type'] ?? 'pending_check'),
				'remote_content_id' => (string) ($entry['remote_content_id'] ?? ''),
				'title' => (string) ($entry['title'] ?? 'FFTimeout'),
				'excerpt' => (string) ($entry['excerpt'] ?? ''),
				'username' => (string) ($entry['username'] ?? ''),
				'available_actions' => ['approve', 'reject'],
				'payload' => [
					'source' => self::SOURCE,
					'forumfortress_challenge_tag' => self::TIMEOUT_TAG,
					'ff_timeout_queue_id' => (string) ($entry['queue_id'] ?? ''),
					'ff_timeout_expires_at' => (int) ($entry['expires_at'] ?? 0),
					'endpoint' => (string) ($entry['endpoint'] ?? 'register'),
					'check_payload' => $check_payload,
					'fortress_public_reason' => 'Forum Fortress check timed out; queued for automatic recovery on next sync (FFTimeout).',
				],
			];
		}
		return $items;
	}

	/**
	 * Retire entries only after the control plane has confirmed that recovery or
	 * the corresponding moderation action was durably acknowledged.
	 *
	 * @param list<string> $queue_ids
	 */
	public function acknowledge_recovery(array $queue_ids): void
	{
		if ($queue_ids === [])
		{
			return;
		}

		$pending = $this->load_pending();
		$changed = false;
		foreach ($queue_ids as $queue_id)
		{
			$queue_id = (string) $queue_id;
			if (!isset($pending[$queue_id]) || !is_array($pending[$queue_id]))
			{
				continue;
			}
			$entry = $pending[$queue_id];
			unset($pending[$queue_id]);
			if ((string) ($entry['remote_content_type'] ?? '') !== 'user')
			{
				$this->remove_post_meta((int) ($entry['remote_content_id'] ?? 0));
			}
			$changed = true;
		}
		if ($changed)
		{
			$this->save_pending($pending);
		}
	}

	public function post_meta(int $post_id): ?array
	{
		if ($post_id <= 0)
		{
			return null;
		}
		$all = $this->load_post_meta();
		$meta = $all[(string) $post_id] ?? null;
		return is_array($meta) ? $meta : null;
	}

	public function attach_post_meta(int $post_id, string $endpoint, array $check_payload): void
	{
		if ($post_id <= 0)
		{
			return;
		}
		$all = $this->load_post_meta();
		$all[(string) $post_id] = [
			'source' => self::SOURCE,
			'forumfortress_challenge_tag' => self::TIMEOUT_TAG,
			'endpoint' => $endpoint,
			'check_payload' => $check_payload,
			'fortress_public_reason' => 'Forum Fortress check timed out; queued for automatic recovery on next sync (FFTimeout).',
			'queued_at' => time(),
			'expires_at' => time() + self::ENTRY_TTL_SECONDS,
		];
		$this->save_post_meta($all);
	}

	public function hold_post_unapproved(int $post_id, int $topic_id, int $forum_id, int $poster_id): void
	{
		if (!defined('ITEM_UNAPPROVED'))
		{
			global $phpbb_root_path, $phpEx;
			include $phpbb_root_path . 'includes/constants.' . $phpEx;
		}
		if ($post_id <= 0)
		{
			return;
		}
		$sql = 'SELECT topic_first_post_id, topic_last_post_id
			FROM ' . $this->topics_table . '
			WHERE topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!is_array($topic))
		{
			return;
		}

		$this->content_visibility->set_post_visibility(
			ITEM_UNAPPROVED,
			$post_id,
			$topic_id,
			$forum_id,
			$poster_id,
			time(),
			'',
			(int) ($topic['topic_first_post_id'] ?? 0) === $post_id,
			(int) ($topic['topic_last_post_id'] ?? 0) === $post_id
		);
	}

	protected function load_pending(): array
	{
		$raw = (string) ($this->config_text->get('ffprotect_timeout_pending') ?? '');
		if ($raw === '')
		{
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded))
		{
			return [];
		}
		$pending = $this->prune_pending($decoded);
		if ($pending !== $decoded)
		{
			$this->save_pending($pending);
		}
		return $pending;
	}

	protected function save_pending(array $pending): void
	{
		$this->config_text->set('ffprotect_timeout_pending', json_encode($pending));
	}

	protected function load_post_meta(): array
	{
		$raw = (string) ($this->config_text->get('ffprotect_timeout_post_meta') ?? '');
		if ($raw === '')
		{
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded))
		{
			return [];
		}
		$meta = $this->prune_post_meta($decoded);
		if ($meta !== $decoded)
		{
			$this->save_post_meta($meta);
		}
		return $meta;
	}

	protected function save_post_meta(array $meta): void
	{
		$this->config_text->set('ffprotect_timeout_post_meta', json_encode($meta));
	}

	protected function queue_id(string $remote_type, string $remote_id): string
	{
		return $remote_type . ':' . $remote_id;
	}

	protected function prune_pending(array $pending): array
	{
		$now = time();
		$valid = [];
		foreach ($pending as $entry)
		{
			if (!is_array($entry))
			{
				continue;
			}
			$type = (string) ($entry['remote_content_type'] ?? '');
			$id = (string) ($entry['remote_content_id'] ?? '');
			$queued_at = (int) ($entry['queued_at'] ?? 0);
			$expires_at = (int) ($entry['expires_at'] ?? ($queued_at > 0 ? $queued_at + self::ENTRY_TTL_SECONDS : 0));
			if (!in_array($type, ['thread', 'post', 'user'], true) || !preg_match('/^[1-9][0-9]*$/D', $id) || $expires_at <= $now)
			{
				continue;
			}
			$queue_id = $this->queue_id($type, $id);
			$entry['queue_id'] = $queue_id;
			$entry['expires_at'] = $expires_at;
			$valid[$queue_id] = $entry;
		}

		uasort($valid, function (array $left, array $right): int {
			return (int) ($right['queued_at'] ?? 0) <=> (int) ($left['queued_at'] ?? 0);
		});
		if (count($valid) > self::MAX_PENDING_ENTRIES)
		{
			$valid = array_slice($valid, 0, self::MAX_PENDING_ENTRIES, true);
		}
		return $valid;
	}

	protected function prune_post_meta(array $meta): array
	{
		$now = time();
		$valid = [];
		foreach ($meta as $post_id => $entry)
		{
			$queued_at = is_array($entry) ? (int) ($entry['queued_at'] ?? 0) : 0;
			$expires_at = is_array($entry) ? (int) ($entry['expires_at'] ?? ($queued_at > 0 ? $queued_at + self::ENTRY_TTL_SECONDS : 0)) : 0;
			if (!is_array($entry) || (int) $post_id <= 0 || $expires_at <= $now)
			{
				continue;
			}
			$entry['expires_at'] = $expires_at;
			$valid[(string) (int) $post_id] = $entry;
		}
		if (count($valid) > self::MAX_PENDING_ENTRIES)
		{
			uasort($valid, function (array $left, array $right): int {
				return (int) ($right['queued_at'] ?? 0) <=> (int) ($left['queued_at'] ?? 0);
			});
			$valid = array_slice($valid, 0, self::MAX_PENDING_ENTRIES, true);
		}
		return $valid;
	}

	protected function remove_post_meta(int $post_id): void
	{
		if ($post_id <= 0)
		{
			return;
		}
		$meta = $this->load_post_meta();
		if (!isset($meta[(string) $post_id]))
		{
			return;
		}
		unset($meta[(string) $post_id]);
		$this->save_post_meta($meta);
	}
}

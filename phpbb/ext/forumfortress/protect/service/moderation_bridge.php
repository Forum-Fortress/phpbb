<?php

namespace forumfortress\protect\service;

use phpbb\config\config;
use phpbb\content_visibility;
use phpbb\db\driver\driver_interface;
use phpbb\user;

use function in_array;
use function is_array;
use function mb_substr;
use function preg_replace;
use function str_replace;
use function strip_tags;
use function substr;
use function time;
use function trim;

class moderation_bridge
{
	protected driver_interface $db;
	protected config $config;
	protected user $user;
	protected string $posts_table;
	protected string $topics_table;
	protected string $users_table;
	protected string $root_path;
	protected string $php_ext;
	protected content_visibility $content_visibility;
	protected timeout_queue $timeout_queue;

	public function __construct(
		driver_interface $db,
		config $config,
		user $user,
		string $posts_table,
		string $topics_table,
		string $users_table,
		string $root_path,
		string $php_ext,
		content_visibility $content_visibility,
		timeout_queue $timeout_queue
	) {
		$this->db = $db;
		$this->config = $config;
		$this->user = $user;
		$this->posts_table = $posts_table;
		$this->topics_table = $topics_table;
		$this->users_table = $users_table;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
		$this->content_visibility = $content_visibility;
		$this->timeout_queue = $timeout_queue;
	}

	public function collect_queue_items(): array
	{
		if (!defined('ITEM_UNAPPROVED'))
		{
			include $this->root_path . 'includes/constants.' . $this->php_ext;
		}
		if (!function_exists('generate_board_url'))
		{
			include $this->root_path . 'includes/functions.' . $this->php_ext;
		}

		$sql = 'SELECT p.post_id, p.topic_id, p.forum_id, p.post_time, p.post_text, p.post_username, p.poster_id, p.post_visibility,
				t.topic_first_post_id, t.topic_last_post_id, t.topic_title, t.topic_last_post_time,
				u.username AS poster_username, u.user_email
			FROM ' . $this->posts_table . ' p
			INNER JOIN ' . $this->topics_table . ' t ON (p.topic_id = t.topic_id)
			LEFT JOIN ' . $this->users_table . ' u ON (p.poster_id = u.user_id)
			WHERE ' . $this->db->sql_in_set('p.post_visibility', [ITEM_UNAPPROVED, ITEM_REAPPROVE]) . '
			ORDER BY p.post_time ASC';
		$result = $this->db->sql_query_limit($sql, 200);
		$output = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$mapped = $this->map_queue_row($row);
			if ($mapped)
			{
				$output[] = $mapped;
			}
		}
		$this->db->sql_freeresult($result);

		return $output;
	}

	protected function map_queue_row(array $row): ?array
	{
		$post_id = (int) ($row['post_id'] ?? 0);
		$topic_first = (int) ($row['topic_first_post_id'] ?? 0);
		if ($post_id <= 0)
		{
			return null;
		}

		$remote_type = ($post_id === $topic_first) ? 'thread' : 'post';
		$title = trim((string) ($row['topic_title'] ?? ''));
		if ($title === '')
		{
			$title = 'Reply';
		}
		$excerpt = $this->excerpt_from_post_text((string) ($row['post_text'] ?? ''));
		$username = trim((string) ($row['poster_username'] ?? $row['post_username'] ?? ''));

		$content_url = null;
		if (function_exists('generate_board_url'))
		{
			$board = rtrim((string) generate_board_url(), '/');
			if ($board !== '')
			{
				$content_url = $board . '/viewtopic.' . $this->php_ext . '?p=' . $post_id . '#p' . $post_id;
			}
		}

		$available_actions = ['approve', 'reject'];
		if ((int) ($row['poster_id'] ?? 0) > 0)
		{
			$available_actions[] = 'spam_clean';
		}

		$payload = [
			'content_type' => $remote_type,
			'topic_id' => (int) ($row['topic_id'] ?? 0),
			'forum_id' => (int) ($row['forum_id'] ?? 0),
		];
		$meta = $this->timeout_queue->post_meta($post_id);
		if (is_array($meta))
		{
			$payload = array_merge($payload, $meta);
		}

		return [
			'remote_content_type' => $remote_type,
			'remote_content_id' => (string) $post_id,
			'title' => $title ?: null,
			'excerpt' => $excerpt !== '' ? $excerpt : null,
			'username' => $username !== '' ? $username : null,
			'remote_user_id' => isset($row['poster_id']) ? (string) (int) $row['poster_id'] : null,
			'content_date' => isset($row['post_time']) ? (int) $row['post_time'] : null,
			'content_url' => $content_url,
			'available_actions' => $available_actions,
			'payload' => $payload,
		];
	}

	protected function excerpt_from_post_text(string $text): string
	{
		$plain = preg_replace('/\\[\\/?[^\\]]+\\]/', ' ', $text);
		$plain = str_replace("\n", ' ', (string) $plain);
		$plain = trim(strip_tags($plain));
		if (function_exists('mb_substr'))
		{
			return mb_substr($plain, 0, 280);
		}
		return substr($plain, 0, 280);
	}

	public function execute_actions(array $actions): array
	{
		$this->ensure_visibility_constants();

		$results = [];
		$actor_id = $this->resolve_actor_user_id();

		foreach ($actions as $action)
		{
			$action_id = (int) ($action['id'] ?? 0);
			$content_type = (string) ($action['remote_content_type'] ?? '');
			$remote_content_id = (string) ($action['remote_content_id'] ?? '');
			$requested = (string) ($action['action'] ?? '');

			if (!$action_id)
			{
				$results[] = ['id' => $action_id, 'status' => 'failed', 'message' => 'Unsupported moderation action payload'];
				continue;
			}

			if ($content_type === 'user')
			{
				$results[] = $this->execute_timeout_user_action($action_id, $remote_content_id, $requested);
				continue;
			}

			$content_id = $this->normalise_positive_id($remote_content_id);
			if (!$content_id || !in_array($content_type, ['thread', 'post'], true))
			{
				$results[] = ['id' => $action_id, 'status' => 'failed', 'message' => 'Unsupported moderation action payload'];
				continue;
			}

			$post_row = $this->load_post_row($content_id);
			if (!$post_row)
			{
				$results[] = ['id' => $action_id, 'status' => 'applied', 'message' => 'Queue item no longer pending'];
				continue;
			}

			if (!$this->matches_content_type($post_row, $content_type))
			{
				$results[] = ['id' => $action_id, 'status' => 'applied', 'message' => 'Queue item content type no longer matches local post'];
				continue;
			}

			if (!$this->is_pending_post($post_row))
			{
				$results[] = ['id' => $action_id, 'status' => 'applied', 'message' => 'Queue item no longer pending'];
				continue;
			}

			try
			{
				if ($requested === 'approve')
				{
					$this->approve_post($post_row, $actor_id);
				}
				else if ($requested === 'reject' || $requested === 'spam_clean')
				{
					$this->reject_post($post_row, $actor_id);
				}
				else
				{
					$results[] = ['id' => $action_id, 'status' => 'failed', 'message' => 'Unknown action'];
					continue;
				}
				$results[] = ['id' => $action_id, 'status' => 'applied', 'message' => 'Action applied'];
			}
			catch (\Throwable $e)
			{
				$results[] = ['id' => $action_id, 'status' => 'failed', 'message' => $e->getMessage()];
			}
		}

		return $results;
	}

	/**
	 * @param list<array<string, mixed>> $notes
	 */
	public function apply_queue_notes(array $notes): void
	{
		$this->ensure_visibility_constants();

		foreach ($notes as $note)
		{
			if (!is_array($note))
			{
				continue;
			}
			$type = (string) ($note['remote_content_type'] ?? '');
			$post_id = (int) ($note['remote_content_id'] ?? 0);
			$reason = trim((string) ($note['fortress_public_reason'] ?? ''));
			if ($post_id <= 0 || $reason === '' || !in_array($type, ['thread', 'post'], true))
			{
				continue;
			}
			if (function_exists('mb_substr'))
			{
				$reason = mb_substr($reason, 0, 2000);
			}
			else
			{
				$reason = substr($reason, 0, 2000);
			}
			$post_row = $this->load_post_row($post_id);
			if (!$post_row || !$this->matches_content_type($post_row, $type) || !$this->is_pending_post($post_row))
			{
				continue;
			}

			// Do not overwrite a reason set by a local moderator or an earlier queue update.
			if (trim((string) ($post_row['post_edit_reason'] ?? '')) !== '')
			{
				continue;
			}

			$sql = 'UPDATE ' . $this->posts_table . "
				SET post_edit_reason = '" . $this->db->sql_escape($reason) . "'
				WHERE post_id = " . (int) $post_id . '
					AND ' . $this->db->sql_in_set('post_visibility', [ITEM_UNAPPROVED, ITEM_REAPPROVE]) . "
					AND post_edit_reason = ''";
			$this->db->sql_query($sql);
		}
	}

	protected function ensure_visibility_constants(): void
	{
		if (!defined('ITEM_APPROVED'))
		{
			include $this->root_path . 'includes/constants.' . $this->php_ext;
		}
	}

	/**
	 * Remote queue types are derived from a post's position in its topic.
	 */
	protected function matches_content_type(array $post_row, string $content_type): bool
	{
		$is_first_post = (int) ($post_row['post_id'] ?? 0) === (int) ($post_row['topic_first_post_id'] ?? 0);

		return ($content_type === 'thread' && $is_first_post)
			|| ($content_type === 'post' && !$is_first_post);
	}

	/**
	 * Only posts still awaiting moderation may be changed by a remote queue action.
	 */
	protected function is_pending_post(array $post_row): bool
	{
		return in_array((int) ($post_row['post_visibility'] ?? ITEM_APPROVED), [ITEM_UNAPPROVED, ITEM_REAPPROVE], true);
	}

	/**
	 * Timeout registration queue contract:
	 * - remote_content_type must be "user".
	 * - remote_content_id must be the decimal phpBB user_id of the newly created account.
	 *
	 * Hashes or other synthetic timeout identifiers are deliberately rejected: they
	 * cannot be mapped safely to a local account. Approval is an acknowledgement
	 * only; a reject/block deactivates a normal account and never deletes it.
	 */
	protected function execute_timeout_user_action(int $action_id, string $remote_content_id, string $requested): array
	{
		$user_id = $this->normalise_positive_id($remote_content_id);
		if (!$user_id)
		{
			return ['id' => $action_id, 'status' => 'failed', 'message' => 'Timeout user action requires a numeric phpBB user_id'];
		}

		if (!in_array($requested, ['approve', 'reject', 'block', 'spam_clean'], true))
		{
			return ['id' => $action_id, 'status' => 'failed', 'message' => 'Unknown action'];
		}

		$user_row = $this->load_user_row($user_id);
		if (!$user_row)
		{
			return ['id' => $action_id, 'status' => 'applied', 'message' => 'Timeout registration user no longer exists'];
		}

		if ((int) $user_row['user_id'] === ANONYMOUS || (int) $user_row['user_type'] === USER_FOUNDER)
		{
			return ['id' => $action_id, 'status' => 'failed', 'message' => 'Refusing to change protected user account'];
		}

		if ($requested === 'approve')
		{
			return ['id' => $action_id, 'status' => 'applied', 'message' => 'Timeout registration approval acknowledged'];
		}

		if ((int) $user_row['user_type'] === USER_INACTIVE)
		{
			return ['id' => $action_id, 'status' => 'applied', 'message' => 'Timeout registration user is already inactive'];
		}

		if ((int) $user_row['user_type'] !== USER_NORMAL)
		{
			return ['id' => $action_id, 'status' => 'failed', 'message' => 'User cannot be safely deactivated'];
		}

		try
		{
			$this->deactivate_user($user_id);
			return ['id' => $action_id, 'status' => 'applied', 'message' => 'Timeout registration user deactivated'];
		}
		catch (\Throwable $e)
		{
			return ['id' => $action_id, 'status' => 'failed', 'message' => $e->getMessage()];
		}
	}

	protected function normalise_positive_id(string $value): ?int
	{
		if (!preg_match('/^[1-9][0-9]*$/D', $value))
		{
			return null;
		}

		$id = (int) $value;
		return $id > 0 ? $id : null;
	}

	protected function load_post_row(int $post_id): ?array
	{
		$sql = 'SELECT p.*, t.topic_first_post_id, t.topic_last_post_id, t.topic_title, t.topic_last_post_time
			FROM ' . $this->posts_table . ' p
			INNER JOIN ' . $this->topics_table . ' t ON (p.topic_id = t.topic_id)
			WHERE p.post_id = ' . (int) $post_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return is_array($row) ? $row : null;
	}

	protected function load_user_row(int $user_id): ?array
	{
		$sql = 'SELECT user_id, user_type
			FROM ' . $this->users_table . '
			WHERE user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return is_array($row) ? $row : null;
	}

	protected function deactivate_user(int $user_id): void
	{
		if (!function_exists('user_active_flip'))
		{
			include $this->root_path . 'includes/functions_user.' . $this->php_ext;
		}

		user_active_flip('deactivate', $user_id, INACTIVE_PROFILE);
	}

	protected function resolve_actor_user_id(): int
	{
		$uid = (int) ($this->user->data['user_id'] ?? 0);
		if ($uid > 0)
		{
			return $uid;
		}

		$sql = 'SELECT user_id FROM ' . $this->users_table . ' WHERE user_type = 3 ORDER BY user_id ASC';
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (is_array($row) && !empty($row['user_id']))
		{
			return (int) $row['user_id'];
		}

		return 2;
	}

	protected function approve_post(array $post_row, int $mod_user_id): void
	{
		$post_id = (int) $post_row['post_id'];
		$topic_id = (int) $post_row['topic_id'];
		$forum_id = (int) $post_row['forum_id'];
		$is_first = $post_id === (int) $post_row['topic_first_post_id'];
		$is_last = $post_id === (int) $post_row['topic_last_post_id'];

		$this->content_visibility->set_post_visibility(
			ITEM_APPROVED,
			$post_id,
			$topic_id,
			$forum_id,
			$mod_user_id,
			time(),
			'',
			$is_first,
			$is_last
		);
	}

	protected function reject_post(array $post_row, int $mod_user_id): void
	{
		$post_id = (int) $post_row['post_id'];
		$topic_id = (int) $post_row['topic_id'];
		$forum_id = (int) $post_row['forum_id'];
		$is_first = $post_id === (int) $post_row['topic_first_post_id'];
		$is_last = $post_id === (int) $post_row['topic_last_post_id'];

		$this->content_visibility->set_post_visibility(
			ITEM_DELETED,
			$post_id,
			$topic_id,
			$forum_id,
			$mod_user_id,
			time(),
			'Forum Fortress moderation',
			$is_first,
			$is_last
		);
	}
}

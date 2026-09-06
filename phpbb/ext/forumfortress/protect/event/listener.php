<?php

/**
 *
 * Forum Fortress. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
 * @license license.txt GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace forumfortress\protect\event;

use forumfortress\protect\service\api_client;
use phpbb\db\driver\driver_interface;
use phpbb\log\log_interface;
use phpbb\request\request_interface;
use phpbb\user;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function array_merge;
use function in_array;
use function is_array;
use function max;
use function strpos;
use function time;

/**
 * Connects phpBB content and moderation events to Forum Fortress.
 */
class listener implements EventSubscriberInterface
{
	protected api_client $client;
	protected user $user;
	protected request_interface $request;
	protected log_interface $log;
	protected driver_interface $db;
	protected string $users_table;
	protected ?string $last_register_decision = null;

	/** @var array<string, mixed>|null */
	protected ?array $pending_post_timeout = null;

	/** @var array<string, mixed>|null */
	protected ?array $pending_register_timeout = null;

	protected function live_decision(?array $response): string
	{
		if (!is_array($response))
		{
			return 'block';
		}
		$decision = strtolower(trim((string) ($response['decision'] ?? 'allow')));
		return $decision === 'allow' ? 'allow' : 'block';
	}

	/**
	 * A check endpoint must never be allowed to take down a form submission.
	 * A malformed or unavailable response is represented by null, so every
	 * caller can apply the configured fail-open/fail-closed policy uniformly.
	 */
	protected function run_validation_check(string $endpoint, array $payload): ?array
	{
		try
		{
			$this->client->bootstrap_if_needed();
		}
		catch (\Throwable $e)
		{
			// An existing site identity may still be able to complete the check.
		}

		try
		{
			$response = $this->client->check($endpoint, $payload);
		}
		catch (\Throwable $e)
		{
			return null;
		}

		if (!is_array($response))
		{
			return null;
		}

		$code = strtoupper((string) ($response['status_code'] ?? ''));
		if ($code === 'ABOVELIMIT')
		{
			return $response;
		}

		return trim((string) ($response['decision'] ?? '')) !== '' ? $response : null;
	}

	protected function add_unavailable_error(array &$errors): void
	{
		if (!$this->client->fail_open())
		{
			$errors[] = $this->user->lang('FORUMFORTRESS_BLOCKED');
		}
	}

	public function __construct(
		api_client $client,
		user $user,
		request_interface $request,
		log_interface $log,
		driver_interface $db,
		string $users_table
	)
	{
		$this->client = $client;
		$this->user = $user;
		$this->request = $request;
		$this->log = $log;
		$this->db = $db;
		$this->users_table = $users_table;
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.user_setup' => 'on_user_setup',
			'core.ucp_register_data_after' => 'on_register_validate',
			'core.user_add_after' => 'on_user_add_after',
			'core.user_active_flip_after' => 'on_user_active_flip_after',
			'core.message_admin_form_submit_before' => 'on_contact_admin_validate',
			'core.posting_modify_submission_errors' => 'on_posting_validate',
			'core.submit_post_end' => 'on_post_submit',
			'core.ucp_profile_validate_profile_info' => 'on_profile_info_validate',
			'core.ucp_profile_modify_signature' => 'on_signature_validate',
			'core.approve_posts_after' => 'on_posts_approved',
			'core.disapprove_posts_after' => 'on_posts_disapproved',
		];
	}

	public function on_user_setup($event): void
	{
		$lang_set_ext = $event['lang_set_ext'];
		if (!is_array($lang_set_ext))
		{
			$lang_set_ext = [];
		}
		$lang_set_ext[] = [
			'ext_name' => 'forumfortress/protect',
			'lang_set' => 'common',
		];
		$event['lang_set_ext'] = $lang_set_ext;

		// Anonymous bootstrap on first page hit (parity with XenForo spam checkers / no ACP visit required).
		if ($this->client->is_enabled())
		{
			try
			{
				$this->client->bootstrap_if_needed();
			}
			catch (\Throwable $e)
			{
			}

			// Throttled hourly sync on normal HTTP traffic when cron may not run (parity with XenForo Listener::maybeRunScheduledSync).
			try
			{
				$this->client->hourly_sync();
			}
			catch (\Throwable $e)
			{
			}
		}
	}

	public function on_register_validate($event): void
	{
		if (!$event['submit'] || !$this->client->is_enabled() || $this->client->protection_checks_bypassed())
		{
			return;
		}

		$this->last_register_decision = null;
		$data = $event['data'];
		$payload = [
			'ip' => (string) $this->user->ip,
			'username' => (string) ($data['username'] ?? ''),
			'email' => (string) ($data['email'] ?? ''),
			'email_domain' => api_client::email_domain((string) ($data['email'] ?? '')),
			'user_agent' => (string) $this->request->header('User-Agent'),
			'links' => [],
		];
		$response = $this->run_validation_check('register', $payload);
		if ($response === null)
		{
			if ($this->client->last_check_had_timeout() && $this->client->fail_open())
			{
				// A real phpBB user ID is not available until user_add_after.
				$this->pending_register_timeout = [
					'endpoint' => 'register',
					'payload' => $payload,
					'username' => (string) ($data['username'] ?? ''),
				];
			}
			$errors = $event['error'];
			$this->apply_register_decision_error(null, $errors);
			$event['error'] = $errors;
			return;
		}
		$this->last_register_decision = $this->live_decision($response);
		$errors = $event['error'];
		$this->apply_register_decision_error($response, $errors);
		$event['error'] = $errors;
	}

	public function on_user_add_after($event): void
	{
		if (!$this->client->is_enabled())
		{
			return;
		}

		$user_row = $event['user_row'];
		$user_id = (int) ($event['user_id'] ?? 0);
		if (is_array($this->pending_register_timeout) && $user_id > 0)
		{
			$this->client->queue_timeout_recovery(
				(string) ($this->pending_register_timeout['endpoint'] ?? 'register'),
				(array) ($this->pending_register_timeout['payload'] ?? []),
				[
					'remote_content_type' => 'user',
					'remote_content_id' => (string) $user_id,
					'username' => (string) ($user_row['username'] ?? $this->pending_register_timeout['username'] ?? ''),
					'title' => 'Registration (FFTimeout)',
				]
			);
		}
		$this->pending_register_timeout = null;
		if ($this->last_register_decision === 'block' && $this->client->delete_rejected_users_enabled())
		{
			$this->delete_user_if_present($user_id, (string) ($user_row['username'] ?? ''));
		}
		$this->client->report('register', [
			'event_type' => 'register',
			'ip' => (string) ($user_row['user_ip'] ?? $this->user->ip),
			'username' => (string) ($user_row['username'] ?? ''),
			'email' => (string) ($user_row['user_email'] ?? ''),
			'email_domain' => api_client::email_domain((string) ($user_row['user_email'] ?? '')),
			'payload' => [
				'phpbb_user_id' => (int) ($event['user_id'] ?? 0),
			],
		]);
	}

	public function on_user_active_flip_after($event): void
	{
		if (!$this->client->is_enabled())
		{
			return;
		}

		if (!defined('INACTIVE_REGISTER'))
		{
			include $this->client->get_root_path() . 'includes/constants.' . $this->client->get_php_ext();
		}

		$mode = (string) ($event['mode'] ?? '');
		if ($mode !== 'activate')
		{
			return;
		}

		$reason = (int) ($event['reason'] ?? 0);
		if ($reason !== INACTIVE_REGISTER)
		{
			return;
		}

		$user_id_ary = $event['user_id_ary'] ?? [];
		if (!is_array($user_id_ary))
		{
			return;
		}

		foreach ($user_id_ary as $uid)
		{
			$uid = (int) $uid;
			if ($uid <= 0)
			{
				continue;
			}

			$sql = 'SELECT username, user_email, user_regdate, user_posts
				FROM ' . $this->users_table . '
				WHERE user_id = ' . (int) $uid;
			$result = $this->db->sql_query($sql);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);
			if (!is_array($row))
			{
				continue;
			}

			$payload = $this->client->build_user_payload(array_merge($row, ['username' => $row['username']]));
			$payload['links'] = [];
			$response = $this->run_validation_check('register', $payload);
			if (!is_array($response))
			{
				if (!$this->client->fail_open())
				{
					$this->hold_registered_user_for_review($uid);
				}
				continue;
			}

			$decision = (string) ($response['decision'] ?? 'allow');
			if ($decision === 'block')
			{
				if (!function_exists('user_active_flip'))
				{
					include $this->client->get_root_path() . 'includes/functions_user.' . $this->client->get_php_ext();
				}
				if (function_exists('user_active_flip'))
				{
					user_active_flip('deactivate', $uid, INACTIVE_MANUAL);
				}
				if ($this->client->delete_rejected_users_enabled())
				{
					$this->delete_user_if_present($uid, (string) ($row['username'] ?? ''));
				}
			}
		}
	}

	protected function delete_user_if_present(int $user_id, string $username): void
	{
		if ($user_id <= 0)
		{
			return;
		}
		if (!function_exists('user_delete'))
		{
			include_once($this->client->get_root_path() . 'includes/functions_user.' . $this->client->get_php_ext());
		}
		if (function_exists('user_delete'))
		{
			@user_delete('remove', $user_id, $username ?: false);
		}
	}

	public function on_posting_validate($event): void
	{
		if (!$event['submit'] || !$this->client->is_enabled() || $this->client->protection_checks_bypassed())
		{
			return;
		}

		$mode = (string) $event['mode'];
		if (!in_array($mode, ['post', 'reply'], true))
		{
			return;
		}

		$post_data = $event['post_data'];
		$message = (string) ($post_data['message'] ?? '');
		$links = api_client::filter_external_links(api_client::extract_links($message), $this->client->get_domain());
		$endpoint = $mode === 'post' ? 'topic' : 'reply';
		$payload = [
			'ip' => (string) $this->user->ip,
			'username' => (string) ($this->user->data['username'] ?? ''),
			'content' => $message,
			'links' => $links,
			'post_count' => (int) ($this->user->data['user_posts'] ?? 0),
			'account_age_seconds' => !empty($this->user->data['user_regdate']) ? max(0, time() - (int) $this->user->data['user_regdate']) : 0,
		];
		$response = $this->run_validation_check($endpoint, $payload);
		if ($response === null && $this->client->last_check_had_timeout())
		{
			$this->pending_post_timeout = [
				'endpoint' => $endpoint,
				'payload' => $payload,
			];
			$errors = $event['error'];
			$this->apply_decision_error($response, $errors);
			$event['error'] = $errors;
			return;
		}
		$this->pending_post_timeout = null;
		$errors = $event['error'];
		$this->apply_decision_error($response, $errors);
		$event['error'] = $errors;
	}

	public function on_contact_admin_validate($event): void
	{
		if (!$this->client->is_enabled() || $this->client->protection_checks_bypassed())
		{
			return;
		}
		$body = trim((string) ($event['body'] ?? ''));
		$subject = trim((string) ($event['subject'] ?? ''));
		$content = trim($subject . "\n\n" . $body);
		if ($content === '')
		{
			return;
		}
		$response = $this->run_validation_check('contact_page', [
			'ip' => (string) $this->user->ip,
			'username' => (string) ($this->user->data['username'] ?? ''),
			'content' => $content,
			'links' => api_client::filter_external_links(api_client::extract_links($content), $this->client->get_domain()),
			'user_agent' => (string) $this->request->header('User-Agent'),
		]);
		$errors = $event['errors'];
		$this->apply_decision_error($response, $errors);
		$event['errors'] = $errors;
	}

	public function on_post_submit($event): void
	{
		if (!$this->client->is_enabled())
		{
			return;
		}

		if (is_array($this->pending_post_timeout))
		{
			$post_id = (int) ($event['data']['post_id'] ?? 0);
			$topic_id = (int) ($event['data']['topic_id'] ?? 0);
			$forum_id = (int) ($event['data']['forum_id'] ?? 0);
			$poster_id = (int) ($this->user->data['user_id'] ?? 0);
			if ($post_id > 0)
			{
				$this->client->queue_timeout_recovery(
					(string) ($this->pending_post_timeout['endpoint'] ?? 'reply'),
					(array) ($this->pending_post_timeout['payload'] ?? []),
					[
						// submit_post_end retains the posting mode; topic_first_post_id is
						// not guaranteed to be present in the event data on every phpBB 3.3 release.
						'remote_content_type' => ((string) ($event['mode'] ?? '') === 'post') ? 'thread' : 'post',
						'remote_content_id' => (string) $post_id,
						'username' => (string) ($this->user->data['username'] ?? ''),
					]
				);
			}
			$this->pending_post_timeout = null;
		}

		$mode = (string) $event['mode'];
		if (!in_array($mode, ['post', 'reply'], true))
		{
			return;
		}

		$data = $event['data'];
		$message = (string) ($data['message'] ?? '');
		$links = api_client::filter_external_links(api_client::extract_links($message), $this->client->get_domain());
		$post_visibility = (int) ($event['post_visibility'] ?? ($data['post_visibility'] ?? ITEM_APPROVED));
		if (!in_array($post_visibility, [ITEM_UNAPPROVED, ITEM_REAPPROVE, ITEM_DELETED], true))
		{
			return;
		}

		$this->client->report('moderation', [
			'event_type' => $mode === 'post' ? 'topic' : 'reply',
			'ip' => (string) $this->user->ip,
			'username' => (string) ($this->user->data['username'] ?? ''),
			'domain' => $this->client->get_domain(),
			'content' => $message !== '' ? $message : null,
			'links' => $links,
			'payload' => [
				'post_id' => (int) ($data['post_id'] ?? 0),
				'topic_id' => (int) ($data['topic_id'] ?? 0),
				'forum_id' => (int) ($data['forum_id'] ?? 0),
				'post_visibility' => $post_visibility,
			],
		]);
	}

	public function on_profile_info_validate($event): void
	{
		if (!$event['submit'] || !$this->client->is_enabled() || $this->client->protection_checks_bypassed())
		{
			return;
		}

		$data = $event['data'];
		$chunks = [];
		$profile_fields = [];
		$jabber = trim((string) ($data['jabber'] ?? ''));
		if ($jabber !== '')
		{
			$chunks[] = $jabber;
			$profile_fields['jabber'] = $jabber;
		}

		$post_variable_names = $this->request->variable_names(request_interface::POST);
		if (is_array($post_variable_names))
		{
			foreach ($post_variable_names as $key)
			{
				$key = (string) $key;
				if (strpos($key, 'pf_') !== 0)
				{
					continue;
				}
				$value = $this->request->variable($key, '', true, request_interface::POST);
				$trimmed = trim($value);
				if ($trimmed === '')
				{
					continue;
				}
				$profile_fields[$key] = $trimmed;
				$chunks[] = $trimmed;
			}
		}

		if ($chunks === [])
		{
			return;
		}

		$combined = trim(implode("\n\n", $chunks));
		$links = api_client::filter_external_links(api_client::extract_links($combined), $this->client->get_domain());
		$response = $this->run_validation_check('profile', [
			'ip' => (string) $this->user->ip,
			'username' => (string) ($this->user->data['username'] ?? ''),
			'profile_fields' => $profile_fields,
			'content' => $combined,
			'links' => $links,
			'post_count' => (int) ($this->user->data['user_posts'] ?? 0),
			'account_age_seconds' => !empty($this->user->data['user_regdate']) ? max(0, time() - (int) $this->user->data['user_regdate']) : 0,
		]);
		$errors = $event['error'];
		$this->apply_decision_error($response, $errors);
		$event['error'] = $errors;
	}

	public function on_signature_validate($event): void
	{
		if (!$event['submit'] || !$this->client->is_enabled() || $this->client->protection_checks_bypassed())
		{
			return;
		}

		$signature = trim((string) $event['signature']);
		if ($signature === '')
		{
			return;
		}

		$links = api_client::filter_external_links(api_client::extract_links($signature), $this->client->get_domain());
		$response = $this->run_validation_check('signature', [
			'ip' => (string) $this->user->ip,
			'username' => (string) ($this->user->data['username'] ?? ''),
			'signature_text' => $signature,
			'links' => $links,
			'post_count' => (int) ($this->user->data['user_posts'] ?? 0),
			'account_age_seconds' => !empty($this->user->data['user_regdate']) ? max(0, time() - (int) $this->user->data['user_regdate']) : 0,
		]);
		$errors = $event['error'];
		$this->apply_decision_error($response, $errors);
		$event['error'] = $errors;
	}

	protected function apply_register_decision_error(?array $response, array &$errors): void
	{
		if (!is_array($response))
		{
			$this->add_unavailable_error($errors);
			return;
		}

		$code = strtoupper((string) ($response['status_code'] ?? ''));
		if ($code === 'ABOVELIMIT')
		{
			if ($this->client->fail_open())
			{
				return;
			}
			$errors[] = $this->user->lang('FORUMFORTRESS_ABOVE_LIMIT');
			return;
		}

		$decision = (string) ($response['decision'] ?? '');
		if ($decision === 'block')
		{
			$errors[] = $this->user->lang('FORUMFORTRESS_BLOCKED');
		}
	}

	protected function hold_registered_user_for_review(int $user_id): void
	{
		if ($user_id <= 0)
		{
			return;
		}

		if (!defined('INACTIVE_PROFILE'))
		{
			include $this->client->get_root_path() . 'includes/constants.' . $this->client->get_php_ext();
		}

		if (!function_exists('user_active_flip'))
		{
			include $this->client->get_root_path() . 'includes/functions_user.' . $this->client->get_php_ext();
		}

		if (function_exists('user_active_flip'))
		{
			user_active_flip('deactivate', $user_id, INACTIVE_PROFILE);
		}
	}

	protected function apply_decision_error(?array $response, array &$errors): void
	{
		if (!is_array($response))
		{
			$this->add_unavailable_error($errors);
			return;
		}

		$code = strtoupper((string) ($response['status_code'] ?? ''));
		if ($code === 'ABOVELIMIT')
		{
			if ($this->client->fail_open())
			{
				return;
			}
			$errors[] = $this->user->lang('FORUMFORTRESS_ABOVE_LIMIT');
			return;
		}

		$decision = $this->live_decision(is_array($response) ? $response : null);
		if ($decision === 'block')
		{
			$errors[] = $this->user->lang('FORUMFORTRESS_BLOCKED');
		}
	}

	public function on_posts_approved($event): void
	{
		if (!$this->client->is_enabled() || !$this->client->send_ham_enabled())
		{
			return;
		}

		$post_info = $event['post_info'];
		foreach ($post_info as $post_id => $post)
		{
			if (!is_array($post))
			{
				continue;
			}

			$this->client->report('ham', [
				'event_type' => !empty($post['topic_first_post_id']) && (int) $post['topic_first_post_id'] === (int) $post_id ? 'topic' : 'reply',
				'domain' => $this->client->get_domain(),
				'ip' => (string) ($post['poster_ip'] ?? null),
				'username' => (string) ($post['username'] ?? $post['post_username'] ?? ''),
				'email_domain' => api_client::email_domain((string) ($post['user_email'] ?? '')),
				'links' => api_client::filter_external_links(api_client::extract_links((string) ($post['post_text'] ?? '')), $this->client->get_domain()),
				'content_hash' => !empty($post['post_text']) ? sha1((string) $post['post_text']) : null,
				'payload' => [
					'post_id' => (int) $post_id,
					'topic_id' => (int) ($post['topic_id'] ?? 0),
					'forum_id' => (int) ($post['forum_id'] ?? 0),
					'action' => (string) ($event['action'] ?? 'approve'),
				],
			]);
		}
	}

	public function on_posts_disapproved($event): void
	{
		if (!$this->client->is_enabled())
		{
			return;
		}

		$post_info = $event['post_info'];
		foreach ($post_info as $post_id => $post)
		{
			if (!is_array($post))
			{
				continue;
			}

			$this->client->report('moderation', [
				'event_type' => !empty($post['topic_first_post_id']) && (int) $post['topic_first_post_id'] === (int) $post_id ? 'topic' : 'reply',
				'domain' => $this->client->get_domain(),
				'ip' => (string) ($post['poster_ip'] ?? null),
				'username' => (string) ($post['username'] ?? $post['post_username'] ?? ''),
				'email_domain' => api_client::email_domain((string) ($post['user_email'] ?? '')),
				'links' => api_client::filter_external_links(api_client::extract_links((string) ($post['post_text'] ?? '')), $this->client->get_domain()),
				'content_hash' => !empty($post['post_text']) ? sha1((string) $post['post_text']) : null,
				'payload' => [
					'post_id' => (int) $post_id,
					'topic_id' => (int) ($post['topic_id'] ?? 0),
					'forum_id' => (int) ($post['forum_id'] ?? 0),
					'reason' => (string) ($event['disapprove_reason'] ?? ''),
					'reason_lang' => (string) ($event['disapprove_reason_lang'] ?? ''),
				],
			]);
		}
	}
}

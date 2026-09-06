<?php

/**
 *
 * Forum Fortress. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
 * @license license.txt GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace forumfortress\protect\acp;

if (!defined('IN_PHPBB'))
{
	exit;
}

/**
 * Forum Fortress ACP settings and diagnostics module.
 */
class main_module
{
	protected const CONNECTION_TEST_TIMEOUT_SECONDS = 2;
	protected const MAX_REGISTRATION_EMAIL_LENGTH = 254;
	protected const MASKED_KEY_PREFIX_LENGTH = 6;
	protected const MASKED_KEY_SUFFIX_LENGTH = 4;
	protected const MASKED_KEY_VISIBLE_LENGTH = 10;

	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $config, $request, $template, $user, $phpbb_container;

		$user->add_lang_ext('forumfortress/protect', 'common');
		$this->tpl_name = 'forumfortress';
		$this->page_title = $user->lang('ACP_FORUMFORTRESS_SETTINGS');
		$client = $phpbb_container->get('forumfortress.protect.api_client');

		add_form_key('forumfortress_settings');
		$action_result = null;
		$auto_bootstrap = null;
		$site_status = null;
		$forum_stats = null;
		$plugin_release = null;
		$portal_direct_url = '';
		$did_bootstrap = false;
		$early_bootstrap_payload = null;

		if ($client->is_enabled() && trim((string) ($config['ffprotect_api_key'] ?? '')) === '' && trim((string) ($config['ffprotect_api_base_url'] ?? '')) !== '')
		{
			try
			{
				$early_bootstrap_payload = $client->bootstrap_if_needed();
				$did_bootstrap = is_array($early_bootstrap_payload);
			}
			catch (\Throwable $e)
			{
			}
		}

		try
		{
			$client->refresh_endpoint_catalog_and_health($did_bootstrap, self::CONNECTION_TEST_TIMEOUT_SECONDS);
		}
		catch (\Throwable $e)
		{
		}

		$endpoint_summary = $client->endpoint_state_summary();
		$endpoint_snapshot = $client->endpoint_state_snapshot();
		$endpoint_latency_rows = $client->build_endpoint_latency_rows();

		if ($request->is_set_post('submit'))
		{
			if (!check_form_key('forumfortress_settings'))
			{
				trigger_error('FORM_INVALID');
			}
			$api_region = \forumfortress\protect\service\ff_api_resilience::normaliseApiRegion($request->variable('ffprotect_api_region', 'global', true));
			$api_base_url = \forumfortress\protect\service\ff_api_resilience::apiBaseUrlForRegion($api_region);
			$api_key = trim($request->variable('ffprotect_api_key', '', true));
			if ($api_key !== '' && preg_match('/[\r\n]/', $api_key) === 1)
			{
				trigger_error($user->lang('ACP_FORUMFORTRESS_API_KEY_LINE_BREAKS') . adm_back_link($this->u_action));
			}

			$config->set('ffprotect_enabled', $request->variable('ffprotect_enabled', 0));
			$config->set('ffprotect_api_base_url', $api_base_url);
			$config->set('ffprotect_api_region', $api_region);
			$config->set('ffprotect_allow_global_fallback', $request->variable('ffprotect_allow_global_fallback', 0));
			$config->set('ffprotect_preferred_endpoint', '');
			$config->set('ffprotect_timeout', $client->normalise_timeout_seconds($request->variable('ffprotect_timeout', 3)));
			if ($api_key !== '')
			{
				// A blank ACP field deliberately preserves the existing secret.
				$config->set('ffprotect_api_key', $api_key);
			}
			$config->set('ffprotect_site_id', trim($request->variable('ffprotect_site_id', '', true)));
			$config->set('ffprotect_fail_open', $request->variable('ffprotect_fail_open', 1));
			$config->set('ffprotect_debug_log', $request->variable('ffprotect_debug_log', 0));
			$config->set('ffprotect_send_ham', $request->variable('ffprotect_send_ham', 1));
			$config->set('ffprotect_delete_rejected_users', $request->variable('ffprotect_delete_rejected_users', 0));
			$config->set('ffprotect_bypass_administrators', $request->variable('ffprotect_bypass_administrators', 1));
			$config->set('ffprotect_bypass_moderators', $request->variable('ffprotect_bypass_moderators', 1));

			trigger_error($user->lang('ACP_FORUMFORTRESS_SETTINGS_SAVED') . adm_back_link($this->u_action));
		}

		if ($request->is_set_post('action_test') || $request->is_set_post('action_register'))
		{
			if (!check_form_key('forumfortress_settings'))
			{
				trigger_error('FORM_INVALID');
			}

			if ($request->is_set_post('action_test'))
			{
				$action_result = $this->run_connection_test($client);
				$endpoint_summary = $client->endpoint_state_summary();
				$endpoint_snapshot = $client->endpoint_state_snapshot();
				$endpoint_latency_rows = $client->build_endpoint_latency_rows();
			}
			else if ($request->is_set_post('action_register'))
			{
				$email = trim($request->variable('registration_email', '', true));
				$action_result = $this->run_registration($client, $email);
			}
		}
		else if ($request->is_set_post('action_attack_on') || $request->is_set_post('action_attack_off'))
		{
			if (!check_form_key('forumfortress_settings'))
			{
				trigger_error('FORM_INVALID');
			}

			try
			{
				$payload = $request->is_set_post('action_attack_on')
					? $client->activate_attack_mode()
					: $client->deactivate_attack_mode();
				$action_result = [
					'title' => $this->page_title,
					'error' => null,
					'payloads' => [
						'attack_mode' => $payload
							? 'OK'
							: $this->no_response_detail($user, $client),
					],
				];
			}
			catch (\Throwable $e)
			{
				$action_result = [
					'title' => $this->page_title,
					'error' => $e->getMessage(),
					'payloads' => [],
				];
			}
		}

		if ($client->is_enabled() && trim((string) ($config['ffprotect_api_key'] ?? '')) === '' && trim((string) ($config['ffprotect_api_base_url'] ?? '')) !== '')
		{
			if ($did_bootstrap && is_array($early_bootstrap_payload))
			{
				$auto_bootstrap = [
					'title' => $this->page_title,
					'error' => null,
					'payload' => '',
				];
			}
			else
			{
				$auto_bootstrap = $this->auto_bootstrap_result($client);
				if ($auto_bootstrap !== null && empty($auto_bootstrap['error']))
				{
					$did_bootstrap = true;
				}
			}

			if ($did_bootstrap)
			{
				try
				{
					$client->refresh_endpoint_catalog_and_health(true, self::CONNECTION_TEST_TIMEOUT_SECONDS);
					$endpoint_summary = $client->endpoint_state_summary();
					$endpoint_snapshot = $client->endpoint_state_snapshot();
					$endpoint_latency_rows = $client->build_endpoint_latency_rows();
				}
				catch (\Throwable $e)
				{
				}
			}
		}

		if ($client->is_enabled() && trim((string) ($config['ffprotect_api_key'] ?? '')) !== '')
		{
			try
			{
				$site_status = $client->site_status(self::CONNECTION_TEST_TIMEOUT_SECONDS);
				$forum_stats = $client->forum_stats(self::CONNECTION_TEST_TIMEOUT_SECONDS);
				$plugin_release = $client->plugin_release(self::CONNECTION_TEST_TIMEOUT_SECONDS);
			}
			catch (\Throwable $e)
			{
			}
		}

		if (trim((string) ($config['ffprotect_api_key'] ?? '')) !== '' && trim((string) ($config['ffprotect_site_id'] ?? '')) !== '')
		{
			try
			{
				$payload = $client->portal_launch();
				if ($payload && !empty($payload['portal_url']))
				{
					$portal_direct_url = (string) $payload['portal_url'];
				}
			}
			catch (\Throwable $e)
			{
			}
		}

		$forum_last_synced = (int) ($endpoint_summary['last_site_ping_at'] ?? 0);
		$last_health_at = (int) ($endpoint_summary['last_health_at'] ?? 0);
		$last_failure = is_array($endpoint_snapshot['last_failure'] ?? null) ? $endpoint_snapshot['last_failure'] : null;
		$last_failure_text = $this->format_last_failure($last_failure, $user);
		$template->assign_vars([
			'U_ACTION' => $this->u_action,
			'FFPROTECT_ENABLED' => (int) ($config['ffprotect_enabled'] ?? 0),
			'FFPROTECT_API_BASE_URL' => (string) ($config['ffprotect_api_base_url'] ?? ''),
			'FFPROTECT_API_REGION' => \forumfortress\protect\service\ff_api_resilience::normaliseApiRegion((string) ($config['ffprotect_api_region'] ?? 'global')),
			'FFPROTECT_ALLOW_GLOBAL_FALLBACK' => (int) ($config['ffprotect_allow_global_fallback'] ?? 0),
			'FFPROTECT_TIMEOUT' => $client->normalise_timeout_seconds($config['ffprotect_timeout'] ?? 3),
			'FFPROTECT_SITE_ID' => (string) ($config['ffprotect_site_id'] ?? ''),
			'FFPROTECT_FAIL_OPEN' => (int) ($config['ffprotect_fail_open'] ?? 1),
			'FFPROTECT_DEBUG_LOG' => (int) ($config['ffprotect_debug_log'] ?? 0),
			'FFPROTECT_SEND_HAM' => (int) ($config['ffprotect_send_ham'] ?? 1),
			'FFPROTECT_DELETE_REJECTED_USERS' => (int) ($config['ffprotect_delete_rejected_users'] ?? 0),
			'FFPROTECT_BYPASS_ADMINISTRATORS' => (int) ($config['ffprotect_bypass_administrators'] ?? 1),
			'FFPROTECT_BYPASS_MODERATORS' => (int) ($config['ffprotect_bypass_moderators'] ?? 1),
			'FFPROTECT_DOMAIN' => $client->get_domain(),
			'FFPROTECT_API_KEY_MASKED' => $this->mask_key((string) ($config['ffprotect_api_key'] ?? '')),
			'FFPROTECT_AUTO_BOOTSTRAP' => $auto_bootstrap ? true : false,
			'FFPROTECT_AUTO_BOOTSTRAP_ERROR' => $auto_bootstrap['error'] ?? '',
			'FFPROTECT_ACTION_RESULT' => $action_result ? true : false,
			'FFPROTECT_ACTION_RESULT_TITLE' => $action_result['title'] ?? '',
			'FFPROTECT_ACTION_RESULT_ERROR' => $action_result['error'] ?? '',
			'FFPROTECT_ACTION_RESULT_PAYLOADS' => $action_result['payloads'] ?? [],
			'FFPROTECT_SITE_STATUS' => $site_status ? true : false,
			'FFPROTECT_SITE_PLAN' => (string) ($site_status['plan'] ?? ''),
			'FFPROTECT_SITE_REGISTRATION_REQUIRED' => isset($site_status['registration_required']) ? ((bool) $site_status['registration_required'] ? $user->lang('ACP_FORUMFORTRESS_YES') : $user->lang('ACP_FORUMFORTRESS_NO')) : $user->lang('ACP_FORUMFORTRESS_UNKNOWN'),
			'FFPROTECT_SHOW_REGISTRATION' => (int) ((isset($site_status['registration_required']) && (bool) $site_status['registration_required']) || (empty($config['ffprotect_site_id']))),
			'FFPROTECT_SITE_ATTACK_MODE' => isset($site_status['attack_mode_active']) ? ((bool) $site_status['attack_mode_active'] ? $user->lang('ACP_FORUMFORTRESS_ACTIVE') : $user->lang('ACP_FORUMFORTRESS_INACTIVE')) : $user->lang('ACP_FORUMFORTRESS_UNKNOWN'),
			'FFPROTECT_ATTACK_MODE_ACTIVE' => !empty($site_status['attack_mode_active']),
			'FFPROTECT_SITE_DATASET_VERSION' => (string) ($site_status['dataset_version'] ?? ''),
			'FFPROTECT_PREFERRED_ENDPOINT' => (string) ($endpoint_summary['preferred'] ?? ''),
			'FFPROTECT_PREFERRED_MISSING_ENDPOINT' => (string) ($endpoint_summary['preferred_missing'] ?? ''),
			'FFPROTECT_LAST_HEALTH_AT_TEXT' => $last_health_at > 0 ? gmdate('Y-m-d H:i:s', $last_health_at) . ' UTC' : '',
			'FFPROTECT_LAST_FAILURE_TEXT' => $last_failure_text,
			'FFPROTECT_TEST_ANSWERED_ENDPOINT' => (string) (($action_result['answered_endpoint'] ?? '') ?: ($endpoint_summary['last_responded'] ?? '')),
			'FFPROTECT_FORUM_LAST_SYNCED_TEXT' => $forum_last_synced > 0 ? gmdate('Y-m-d H:i:s', $forum_last_synced) . ' UTC' : '',
			'FFPROTECT_PLUGIN_VERSION' => \forumfortress\protect\service\api_client::PLUGIN_VERSION,
			'FFPROTECT_PLUGIN_RELEASE' => $plugin_release ? true : false,
			'FFPROTECT_PLUGIN_LATEST_VERSION' => (string) ($plugin_release['latest_version'] ?? ''),
			'FFPROTECT_PLUGIN_UPDATE_AVAILABLE' => (int) (!empty($plugin_release['upgrade_available'])),
			'FFPROTECT_PLUGIN_DOWNLOAD_URL' => (string) ($plugin_release['download_url'] ?? ''),
			'FFPROTECT_FORUM_STATS' => $forum_stats ? true : false,
			'FFPROTECT_CURRENT_MONTH_CHECKS' => (int) ($forum_stats['current_month_checks'] ?? 0),
			'FFPROTECT_ALLOWS' => (int) ($forum_stats['allows'] ?? 0),
			'FFPROTECT_BLOCKS' => (int) ($forum_stats['blocks'] ?? 0),
			'FFPROTECT_CAN_PORTAL' => (int) (!empty($config['ffprotect_site_id']) && !empty($config['ffprotect_api_key'])),
			'FFPROTECT_PORTAL_DIRECT_URL' => $portal_direct_url,
		]);

		if (!empty($action_result['payloads']))
		{
			foreach ($action_result['payloads'] as $label => $payload)
			{
				$template->assign_block_vars('ffprotect_payload', [
					'LABEL' => $label,
					'PAYLOAD' => $payload,
				]);
			}
		}

		foreach ($endpoint_latency_rows as $row)
		{
			$template->assign_block_vars('ffprotect_endpoint_latency', [
				'ENDPOINT' => (string) ($row['endpoint'] ?? ''),
				'LATENCY' => (string) ($row['latency'] ?? ''),
				'IS_PREFERRED' => !empty($row['is_preferred']),
			]);
		}
	}

	protected function auto_bootstrap_result($client): ?array
	{
		try
		{
			$payload = $client->bootstrap_if_needed();
			if (!$payload)
			{
				return null;
			}

			return [
				'title' => $this->page_title,
				'error' => null,
				'payload' => '',
			];
		}
		catch (\Throwable $e)
		{
			return [
				'title' => $this->page_title,
				'error' => $e->getMessage(),
				'payload' => '',
			];
		}
	}

	protected function run_connection_test($client)
	{
		global $user;

		$result = [
			'title' => $this->page_title,
			'error' => null,
			'payloads' => [],
		];

		try
		{
			$client->clear_last_request_error();
			$bootstrap = $client->bootstrap_if_needed(true);
			$e_bootstrap = $client->get_last_request_error();
			$client->refresh_endpoints_before_connection_test(self::CONNECTION_TEST_TIMEOUT_SECONDS);
			$health = $client->health(self::CONNECTION_TEST_TIMEOUT_SECONDS);
			$e_health = $client->get_last_request_error();
			$capabilities = $client->capabilities(self::CONNECTION_TEST_TIMEOUT_SECONDS);
			$e_capabilities = $client->get_last_request_error();
			$site_status = $client->site_status(self::CONNECTION_TEST_TIMEOUT_SECONDS);
			$e_site_status = $client->get_last_request_error();
			$forum_stats = $client->forum_stats(self::CONNECTION_TEST_TIMEOUT_SECONDS);
			$e_forum_stats = $client->get_last_request_error();
			$site_ping = $client->site_ping(self::CONNECTION_TEST_TIMEOUT_SECONDS);
			$e_site_ping = $client->get_last_request_error();

			$result['payloads'] = [
				'bootstrap' => $bootstrap !== null ? 'OK' : $this->payload_or_detail($user, $e_bootstrap),
				'health' => $health !== null ? 'OK' : $this->payload_or_detail($user, $e_health),
				'capabilities' => $capabilities !== null ? 'OK' : $this->payload_or_detail($user, $e_capabilities),
				'site_status' => $site_status !== null ? 'OK' : $this->payload_or_detail($user, $e_site_status),
				'forum_stats' => $forum_stats !== null ? 'OK' : $this->payload_or_detail($user, $e_forum_stats),
				'site_ping' => $site_ping !== null ? 'OK' : $this->payload_or_detail($user, $e_site_ping),
			];
			$endpoint_summary = $client->endpoint_state_summary();
			$result['answered_endpoint'] = (string) ($endpoint_summary['last_responded'] ?? '');
			$result['preferred_endpoint'] = (string) ($endpoint_summary['preferred'] ?? '');
			$result['preferred_missing'] = (string) ($endpoint_summary['preferred_missing'] ?? '');
		}
		catch (\Throwable $e)
		{
			$result['error'] = $e->getMessage();
		}

		return $result;
	}

	protected function run_registration($client, $email)
	{
		global $user;

		$result = [
			'title' => $this->page_title,
			'error' => null,
			'payloads' => [],
		];

		if ($email === '')
		{
			$result['error'] = $user->lang('ACP_FORUMFORTRESS_REGISTRATION_EMAIL_REQUIRED');
			return $result;
		}
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > self::MAX_REGISTRATION_EMAIL_LENGTH)
		{
			$result['error'] = $user->lang('ACP_FORUMFORTRESS_REGISTRATION_EMAIL_INVALID');
			return $result;
		}

		try
		{
			$client->clear_last_request_error();
			$client->bootstrap_if_needed(true);
			$payload = $client->register_site($email);
			$result['payloads'] = [
				'registration' => $payload !== null ? 'OK' : $this->no_response_detail($user, $client),
			];
		}
		catch (\Throwable $e)
		{
			$result['error'] = $e->getMessage();
		}

		return $result;
	}

	protected function no_response_detail($user, \forumfortress\protect\service\api_client $client): string
	{
		$err = $client->get_last_request_error();

		return $user->lang('ACP_FORUMFORTRESS_NO_RESPONSE') . ($err ? ' ' . $err : '');
	}

	protected function payload_or_detail($user, ?string $captured_error): string
	{
		return $user->lang('ACP_FORUMFORTRESS_NO_RESPONSE') . ($captured_error ? ' ' . $captured_error : '');
	}

	protected function mask_key($key)
	{
		$key = trim((string) $key);
		if ($key === '')
		{
			return '';
		}

		if (strlen($key) <= self::MASKED_KEY_VISIBLE_LENGTH)
		{
			return str_repeat('*', strlen($key));
		}

		return substr($key, 0, self::MASKED_KEY_PREFIX_LENGTH)
			. str_repeat('*', max(0, strlen($key) - self::MASKED_KEY_VISIBLE_LENGTH))
			. substr($key, -self::MASKED_KEY_SUFFIX_LENGTH);
	}

	protected function format_last_failure(?array $failure, $user): string
	{
		if (!$failure)
		{
			return '';
		}

		$parts = [(string) ($failure['reason'] ?? $user->lang('ACP_FORUMFORTRESS_UNKNOWN'))];
		if (!empty($failure['status']))
		{
			$parts[] = $user->lang('ACP_FORUMFORTRESS_FAILURE_STATUS', (int) $failure['status']);
		}
		if (!empty($failure['path']))
		{
			$parts[] = $user->lang('ACP_FORUMFORTRESS_FAILURE_PATH', (string) $failure['path']);
		}
		if (!empty($failure['base']))
		{
			$parts[] = $user->lang('ACP_FORUMFORTRESS_FAILURE_BASE', (string) $failure['base']);
		}
		if (!empty($failure['at']))
		{
			$parts[] = $user->lang('ACP_FORUMFORTRESS_FAILURE_TIME', gmdate('Y-m-d H:i:s', (int) $failure['at']) . ' UTC');
		}

		return implode(' ', $parts);
	}
}

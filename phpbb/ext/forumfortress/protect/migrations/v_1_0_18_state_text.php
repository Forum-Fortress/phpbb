<?php

namespace forumfortress\protect\migrations;

class v_1_0_18_state_text extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\\forumfortress\\protect\\migrations\\v_1_0_0'];
	}

	public function effectively_installed()
	{
		return !isset($this->config['ffprotect_endpoint_state'])
			&& !isset($this->config['ffprotect_timeout_pending'])
			&& !isset($this->config['ffprotect_timeout_post_meta']);
	}

	public function update_data()
	{
		return [
			['config_text.add', ['ffprotect_endpoint_state', (string) ($this->config['ffprotect_endpoint_state'] ?? '')]],
			['config_text.add', ['ffprotect_timeout_pending', (string) ($this->config['ffprotect_timeout_pending'] ?? '')]],
			['config_text.add', ['ffprotect_timeout_post_meta', (string) ($this->config['ffprotect_timeout_post_meta'] ?? '')]],
			['config.remove', ['ffprotect_endpoint_state']],
			['config.remove', ['ffprotect_timeout_pending']],
			['config.remove', ['ffprotect_timeout_post_meta']],
		];
	}

	public function revert_data()
	{
		return [
			['config.add', ['ffprotect_endpoint_state', '']],
			['config.add', ['ffprotect_timeout_pending', '']],
			['config.add', ['ffprotect_timeout_post_meta', '']],
			['config_text.remove', ['ffprotect_endpoint_state']],
			['config_text.remove', ['ffprotect_timeout_pending']],
			['config_text.remove', ['ffprotect_timeout_post_meta']],
		];
	}
}

<?php

/**
 *
 * Forum Fortress. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
 * @license license.txt GNU General Public License, version 2 or later (GPL-2.0-or-later)
 *
 */

namespace forumfortress\protect;

if (!defined('IN_PHPBB'))
{
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Extension lifecycle handler.
 */
class ext extends \phpbb\extension\base
{
	/**
	 * Prevent activation on runtimes outside the package requirements.
	 */
	public function is_enableable(): bool
	{
		return phpbb_version_compare(PHPBB_VERSION, '3.3.0', '>=')
			&& phpbb_version_compare(PHPBB_VERSION, '3.4.0@dev', '<')
			&& version_compare(PHP_VERSION, '7.4.0', '>=');
	}

	public function enable_step($old_state)
	{
		if ($old_state === false)
		{
			$cache = $this->container->get('cache');
			$cache->destroy('_ext');
		}

		return parent::enable_step($old_state);
	}

	public function purge_step($old_state)
	{
		$state = parent::purge_step($old_state);

		if ($state === false)
		{
			$this->purge_orphan_acp_modules();
		}

		return $state;
	}

	protected function purge_orphan_acp_modules(): void
	{
		if (!$this->container->has('migrator.tool.module'))
		{
			return;
		}

		foreach (migrations\acp_module_data::remove_all() as $step)
		{
			if ($step[0] !== 'module.remove')
			{
				continue;
			}
			$args = $step[1];
			$this->container->get('migrator.tool.module')->remove(
				$args[0],
				$args[1],
				$args[2] ?? ''
			);
		}

		$this->container->get('module.manager')->remove_cache_file('acp');
	}
}

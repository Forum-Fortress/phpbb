<?php

/**
 *
 * Forum Fortress. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Marscastle Ltd trading as Forum Fortress
 * @license license.txt GNU General Public License, version 2 or later (GPL-2.0-or-later)
 *
 */

/**
 * @ignore
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

/*
 * phpBB loads extension-local vendor/autoload.php files before ACP dispatch.
 * Keep this tiny loader in the package so the extension works when Composer is
 * not available on the customer's production host.
 */
spl_autoload_register(static function (string $class): void
{
	$prefix = 'forumfortress\\protect\\';
	if (strncmp($class, $prefix, strlen($prefix)) !== 0)
	{
		return;
	}

	$relative = substr($class, strlen($prefix));
	$path = __DIR__ . '/../' . str_replace('\\', '/', $relative) . '.php';
	if (is_file($path))
	{
		require_once $path;
	}
});

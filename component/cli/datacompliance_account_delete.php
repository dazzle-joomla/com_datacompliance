<?php
/**
 * @package   AkeebaDataCompliance
 * @copyright Copyright (c)2018-2019 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

use Joomla\CMS\Log\Log;
use Joomla\CMS\Log\LogEntry;
use Joomla\CMS\User\UserHelper;

define('_JEXEC', 1);

$path = __DIR__ . '/../administrator/components/com_datacompliance/assets/cli/base.php';

if (file_exists($path))
{
	require_once $path;
}
else
{
	$curDir = getcwd();
	require_once $curDir . '/../administrator/components/com_datacompliance/assets/cli/base.php';
}

class DataComplianceUserDelete extends DataComplianceCliBase
{
	public function execute()
	{
		// Enable debug mode?
		$debug = $this->input->getBool('debug', false);

		if (!defined('JDEBUG'))
		{
			define('JDEBUG', $debug);
		}

		if (JDEBUG)
		{
			Log::addLogger([
				// Logger format. "echo" passes the log message verbatim.
				'logger'   => 'callback',
				'callback' => function (LogEntry $entry) {
					$priorities = array(
						Log::EMERGENCY => 'EMERGENCY',
						Log::ALERT     => 'ALERT',
						Log::CRITICAL  => 'CRITICAL',
						Log::ERROR     => 'ERROR',
						Log::WARNING   => 'WARNING',
						Log::NOTICE    => 'NOTICE',
						Log::INFO      => 'INFO',
						Log::DEBUG     => 'DEBUG',
					);

					$priority = $priorities[$entry->priority];
					$date     = $entry->date->format(JText::_('DATE_FORMAT_FILTER_DATETIME'));

					$this->out(sprintf("[%-9s] %20s -- %s", $priority, $date, $entry->message));
				},

			], Log::ALL, 'com_datacompliance');

			Log::add('Test', Log::DEBUG, 'com_datacompliance');
		}

		$container = \FOF30\Container\Container::getInstance('com_datacompliance', [], 'admin');

		// Load the translations for this component;
		$container->platform->loadTranslations($container->componentName);

		// Load the version information
		include_once $container->backEndPath . '/version.php';

		$version = DATACOMPLIANCE_VERSION;
		$year    = gmdate('Y');

		$this->out("Akeeba Data Compliance $version");
		$this->out("Copyright (c) 2018-$year Akeeba Ltd / Nicholas K. Dionysopoulos");
		$this->out(<<< TEXT
-------------------------------------------------------------------------------
Akeeba Data Compliance is Free Software, distributed under the terms of the GNU
General Public License version 3 or, at your option, any later version.
This program comes with ABSOLUTELY NO WARRANTY as per sections 15 & 16 of the
license. See http://www.gnu.org/licenses/gpl-3.0.html for details.
-------------------------------------------------------------------------------

TEXT
		);

		$username = $this->input->getUsername('username', null);
		$user_id  = $this->input->getInt('id', 0);

		if (empty($user_id) && !empty($username))
		{
			$user_id = UserHelper::getUserId($username);

			if ($user_id == 0)
			{
				$this->out(sprintf('Can not find user ‘%s’.', $username));

				$this->close(254);
			}
		}

		if (empty($user_id))
		{
			$this->out("You must supply a username (--username=example) or user ID (--id=123) to use this script.");

			$this->close(255);
		}

		/** @var \Akeeba\DataCompliance\Admin\Model\Wipe $wipeModel */
		$wipeModel = $container->factory->model('Wipe')->tmpInstance();
		$user      = \Joomla\CMS\Factory::getUser($user_id);

		$this->out("You are going to remove the following user:");
		$this->out("\tUsername: {$user->username}");
		$this->out("\tName:     {$user->name}");
		$this->out("\tEmail:    {$user->email}");

		if (!$wipeModel->checkWipeAbility($user_id, 'admin'))
		{
			$this->out("Sorry, this user cannot be deleted:");
			$this->out($wipeModel->getError());

			$this->close('127');
		}

		$force  = $this->input->getBool('force', false);
		$dryRun = $this->input->getBool('dry-run', false);

		if ($force)
		{
			$this->out('--force option enabled; proceeding anyway.');

			$answer = 'Y';
		}
		elseif ($dryRun)
		{
			$this->out('--dry-run option enabled; aborting anyway.');

			$answer = 'N';
		}
		else
		{
			$this->out("Are you sure you want to proceed [Y/N]?");

			$answer = $this->in();
			$answer = substr(strtoupper($answer), 0, 1);
		}

		if ($answer != 'Y')
		{
			$this->out('Operation aborted at your request');

			$this->close(1);
		}

		$this->out("Removing user $user_id... ", false);

		$result = $wipeModel->wipe($user_id, 'admin');

		if ($result)
		{
			$this->out('[OK]');

			$this->close();
		}

		$error = $wipeModel->getError();
		$this->out('[FAILED]');
		$this->out("\t$error");

		$this->close(127);

		parent::execute();
	}
}

DataComplianceCliBase::getInstance('DataComplianceUserDelete')->execute();
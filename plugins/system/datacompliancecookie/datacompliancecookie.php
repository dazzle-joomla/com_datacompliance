<?php
/**
 * @package   Akeeba Data Compliance
 * @copyright Copyright (c)2018 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

use FOF30\Container\Container;
use FOF30\Utils\DynamicGroups;
use Joomla\CMS\Application\CMSApplication;
use plgSystemDataComplianceCookieHelper as CookieHelper;

// Prevent direct access
defined('_JEXEC') or die;

// Minimum PHP version check
if (!version_compare(PHP_VERSION, '7.0.0', '>='))
{
	return;
}

// Make sure Akeeba DataCompliance is installed
if (!file_exists(JPATH_ADMINISTRATOR . '/components/com_datacompliance'))
{
	return;
}

// Load FOF
if (!defined('FOF30_INCLUDED') && !@include_once(JPATH_LIBRARIES . '/fof30/include.php'))
{
	return;
}

/**
 * Akeeba DataCompliance Cookie Conformance System Plugin
 *
 * Removes cookies unless explicitly allowed
 */
class PlgSystemDatacompliancecookie extends JPlugin
{
	/**
	 * Are we enabled, all requirements met etc?
	 *
	 * @var    bool
	 *
	 * @since  1.1.0
	 */
	public $enabled = true;

	/**
	 * The component's container
	 *
	 * @var    Container
	 *
	 * @since  1.1.0
	 */
	private $container = null;

	/**
	 * Has the user accepted cookies from this site?
	 *
	 * @var    bool
	 *
	 * @since  1.1.0
	 */
	private $hasAcceptedCookies = false;

	/**
	 * Has the user recorded his preference regarding cookies?
	 *
	 * @var    bool
	 *
	 * @since  1.1.0
	 */
	private $hasCookiePreference = false;

	/**
	 * Have I already included the JavaScript in the HTML page?
	 *
	 * @var    bool
	 *
	 * @since  1.1.0
	 */
	private $haveIncludedJavaScript = false;

	/**
	 * Have I already included the CSS in the HTML page?
	 *
	 * @var    bool
	 *
	 * @since  1.1.0
	 */
	private $haveIncludedCSS = false;

	/**
	 * Have I already included the HTML for the cookie banner or controls in the HTML page?
	 *
	 * @var    bool
	 *
	 * @since  1.1.0
	 */
	private $haveIncludedHtml = false;

	/**
	 * Am I currently handling an AJAX request? This is populated in onAfterInitialise and it's used to prevent other
	 * event handlers from firing when we are processing an AJAX request.
	 *
	 * @var    bool
	 *
	 * @since  1.1.0
	 */
	private $inAjax = false;

	/**
	 * Constructor
	 *
	 * @param   object  &$subject  The object to observe
	 * @param   array   $config    An optional associative array of configuration settings.
	 *                             Recognized key values include 'name', 'group', 'params', 'language'
	 *                             (this list is not meant to be comprehensive).
	 *
	 * @since   1.1.0
	 */
	public function __construct($subject, array $config = array())
	{
		parent::__construct($subject, $config);

		// Self-disable on admin pages or when we cannot get a reference to the CMS application (e.g. CLI app).
		try
		{
			if (JFactory::getApplication()->isClient('administrator'))
			{
				throw new RuntimeException("This plugin should not load on administrator pages.");
			}
		}
		catch (Exception $e)
		{
			// This code block also catches the case where JFactory::getApplication() crashes, e.g. CLI applications.
			$this->enabled = false;

			return;
		}

		// Self-disable if our component is not enabled.
		try
		{
			if (!JComponentHelper::isInstalled('com_datacompliance') || !JComponentHelper::isEnabled('com_datacompliance'))
			{
				throw new RuntimeException('Component not installed');
			}

			$this->container = Container::getInstance('com_datacompliance');
		}
		catch (Exception $e)
		{
			$this->enabled = false;

			return;
		}

		// Load the helper class. Self-disable if it's not available.
		if (!class_exists('plgSystemDataComplianceHelper'))
		{
			include_once __DIR__ . '/helper/helper.php';
		}

		if (!class_exists('plgSystemDataComplianceCookieHelper'))
		{
			$this->enabled = false;

			return;
		}

		// Get some options
		$cookieName        = $this->params->get('cookieName', 'plg_system_datacompliancecookie');
		$impliedAcceptance = $this->params->get('impliedAccept', 0) != 0;

		// Set up the name of the user preference (helper) cookie we are going to use in this plugin
		CookieHelper::setCookieName($cookieName);

		// Get the user's cookie acceptance preferences
		$this->hasAcceptedCookies  = CookieHelper::hasAcceptedCookies($impliedAcceptance);
		$this->hasCookiePreference = CookieHelper::getDecodedCookieValue() !== false;
	}

	/**
	 * Handler for AJAX interactions with the plugin
	 *
	 * @return  string|Throwable  The message to send back to the application, or an Exception in case of an error
	 *
	 * @since   1.1.0
	 */
	public function onAjaxDatacompliancecookie()
	{
		// Am I already disabled...?
		if (!$this->enabled)
		{
			return new RuntimeException('Cookie conformance is not applicable', 101);
		}

		// Prevent other event handlers in the plugin from firing
		$this->enabled = false;

		$token    = $this->container->platform->getToken();
		$hasToken = $this->container->input->post->get($token, false, 'none') == 1;

		if (!$hasToken)
		{
			return new RuntimeException('Invalid security token; this request is a forgery and has not been taken into account.', 102);
		}

		$accepted = $this->container->input->post->getInt('accepted', null);
		$reset    = $this->container->input->post->getInt('reset', null);

		if (is_null($accepted) && is_null($reset))
		{
			return new RuntimeException('No cookie preference was provided and no cookie preference reset was requested.', 103);
		}

		if ($reset)
		{
			// Reset the cookie preference. Cookie acceptance is set to the implied acceptance value.
			$accepted = $this->params->get('impliedAccept', 0) != 0;
			CookieHelper::removeCookiePreference($accepted);

			$ret = sprintf("The cookie preference has been cleared. Cookies are now %s per default setting.", $accepted ? 'accepted' : 'rejected');
		}
		else
		{
			// Set the cookie preference to the user's setting.
			$thisManyDays = $this->params->get('cookiePreferenceDuaration', 90);
			CookieHelper::setAcceptedCookies($accepted === 1, $thisManyDays);

			$ret = sprintf("The user has %s cookies", $accepted ? 'accepted' : 'rejected');
		}

		// Apply the user group assignments based on the cookie preference
		$this->applyUserGroupAssignments();

		// Remove all cookies if the user has rejected cookies
		if (!$accepted)
		{
			$this->removeAllCookies();
		}

		return $ret;
	}

	/**
	 * Runs early in the application startup, right after Joomla has done basic preparation and loaded the system
	 * plugins.
	 *
	 * @return  void
	 *
	 * @see     \Joomla\CMS\Application\CMSApplication::initialiseApp()
	 *
	 *
	 * @since   1.1.0
	 */
	public function onAfterInitialise()
	{
		// Am I already disabled...?
		if (!$this->enabled)
		{
			return;
		}

		/**
		 * When we are in com_ajax we should defer execution of this code until after we have handled the request.
		 * Otherwise the I Agree is never honored if the default cookie acceptance state is "declined".
		 */
		$input  = $this->container->input->get;
		$option = $input->getCmd('option', '');
		$group  = $input->getCmd('group', '');
		$plugin = $input->getCmd('plugin', '');

		if (($option == 'com_ajax') && ($group == 'system') && ($plugin == 'datacompliancecookie'))
		{
			$this->inAjax = true;

			return;
		}

		// Apply the user group assignments based on the cookie preference
		$this->applyUserGroupAssignments();

		// Remove all cookies if the user has rejected cookies
		if (!$this->hasAcceptedCookies)
		{
			$this->removeAllCookies();
		}
	}

	/**
	 * Called after Joomla! has routed the application (figured out SEF redirections and is about to load the component)
	 *
	 * @return  void
	 *
	 * @see     \Joomla\CMS\Application\CMSApplication::route()
	 *
	 * @since   1.1.0
	 */
	public function onAfterRoute()
	{
		// Am I already disabled or in AJAX handling mode?
		if (!$this->enabled || $this->inAjax)
		{
			return;
		}

		// If the format is not 'html' or the tmpl is not one of the allowed values we should not run.
		try
		{
			$app = JFactory::getApplication();

			if ($app->input->getCmd('format', 'html') != 'html')
			{
				throw new RuntimeException("This plugin should not run in non-HTML application formats.");
			}

			if (!in_array($app->input->getCmd('tmpl', ''), ['', 'index', 'component'], true))
			{
				throw new RuntimeException("This plugin should not run for application templates which do not predictably result in HTML output.");
			}
		}
		catch (Exception $e)
		{
			$this->enabled = false;

			return;
		}

		if (!$this->hasAcceptedCookies)
		{
			// Remove all cookies before the component is loaded
			$this->removeAllCookies();
		}

		$this->loadCommonJavascript($app);
		$this->loadCommonCSS($app);

		// Note: we cannot load the HTML yet. This can only be done AFTER the document is rendered.
	}

	/**
	 * Called after Joomla! has rendered the document and before it is sent to the browser.
	 *
	 * @return  void
	 *
	 * @see     \Joomla\CMS\Application\CMSApplication::execute()
	 *
	 * @since   1.1.0
	 */
	public function onAfterRender()
	{
		// Am I already disabled or in AJAX handling mode?
		if (!$this->enabled || $this->inAjax)
		{
			return;
		}

		if (!$this->hasAcceptedCookies)
		{
			// Remove any cookies which may have been set by the component and modules
			$this->removeAllCookies();
		}

		try
		{
			// Load the common JavaScript
			$app = JFactory::getApplication();
			$this->loadCommonJavascript($app);
			$this->loadCommonCSS($app);

			$this->loadHtml($app);
		}
		catch (Exception $e)
		{
			// Sorry, we cannot get a Joomla! application :(
		}
	}

	/**
	 * Remove all cookies which are already set or about to be set
	 *
	 * @return  void
	 *
	 * @since   1.1.0
	 */
	private function removeAllCookies()
	{
		$allowSessionCookie      = $this->params->get('allowSessionCookie', 1) !== 0;
		$additionalCookieDomains = $this->getAdditionalCookieDomains();

		CookieHelper::unsetAllCookies($allowSessionCookie, $additionalCookieDomains);
	}

	/**
	 * Get the additional cookie domains as an array
	 *
	 * @return array
	 *
	 * @since  1.1.0
	 */
	private function getAdditionalCookieDomains(): array
	{
		$additionalCookieDomains = trim($this->params->get('additionalCookieDomains', ''));

		if (!empty($additionalCookieDomains))
		{
			$additionalCookieDomains = array_map(function ($x) {
				return trim($x);
			}, explode("\n", $additionalCookieDomains));
		}

		$additionalCookieDomains = is_array($additionalCookieDomains) ? $additionalCookieDomains : [];

		if (empty($additionalCookieDomains))
		{
			$additionalCookieDomains = CookieHelper::getDefaultCookieDomainNames();
		}

		return $additionalCookieDomains;
	}

	/**
	 * Load the common Javascript for this plugin
	 *
	 * @param   CMSApplication  $app      The CMS application we are interfacing
	 * @param   array           $options  Additional options to pass to the JavaScript (overrides defaults)
	 *
	 * @since   1.1.0
	 */
	private function loadCommonJavascript($app, array $options = [])
	{
		// Prevent double inclusion of the JavaScript
		if ($this->haveIncludedJavaScript)
		{
			return;
		}

		$this->haveIncludedJavaScript = true;

		// Get the default options for the cookie killer JavaScript
		$path   = $app->get('cookie_path', '/');
		$domain = $app->get('cookie_domain', filter_input(INPUT_SERVER, 'HTTP_HOST'));

		$whiteList          = [CookieHelper::getCookieName()];
		$allowSessionCookie = $this->params->get('allowSessionCookie', 1) !== 0;

		// If the session cookie is allowed I need to whitelist it too.
		if ($allowSessionCookie)
		{
			$whiteList[] = CookieHelper::getSessionCookieName();
			$whiteList[] = 'joomla_user_state';
		}

		$defaultOptions = [
			'accepted'                => $this->hasAcceptedCookies,
			'interacted'              => $this->hasCookiePreference,
			'cookie'                  => [
				'domain' => $domain,
				'path'   => $path,
			],
			'additionalCookieDomains' => $this->getAdditionalCookieDomains(),
			'whitelisted'             => $whiteList,
			'token'                   => $this->container->platform->getToken(),
		];

		$options     = array_merge_recursive($defaultOptions, $options);
		$optionsJSON = json_encode($options, JSON_PRETTY_PRINT);

		$js = <<< JS
; //
var AkeebaDataComplianceCookiesOptions = $optionsJSON;

JS;

		$this->container->template->addJSInline($js);
		$this->container->template->addJS('media://plg_system_datacompliancecookie/js/datacompliancecookies.js', true, false, $this->container->mediaVersion);

		// Add language strings which should be made known to JS
		$this->loadLanguage();
		JText::script('PLG_SYSTEM_DATACOMPLIANCECOOKIE_LBL_REMOVECOOKIES');
	}

	/**
	 * Load the common CSS for this plugin
	 *
	 * @param   CMSApplication  $app      The CMS application we are interfacing
	 * @param   array           $options  Additional options to pass to the JavaScript (overrides defaults)
	 *
	 * @since   1.1.0
	 */
	private function loadCommonCSS($app, array $options = [])
	{
		// Prevent double inclusion of the CSS
		if ($this->haveIncludedCSS)
		{
			return;
		}

		$this->haveIncludedCSS = true;

		// FEF
		$useFEF   = $this->params->get('load_fef', 1);
		$useReset = $this->params->get('fef_reset', 1);

		if ($useFEF)
		{
			$helperFile = JPATH_SITE . '/media/fef/fef.php';

			if (!class_exists('AkeebaFEFHelper') && is_file($helperFile))
			{
				include_once $helperFile;
			}

			if (class_exists('AkeebaFEFHelper'))
			{
				\AkeebaFEFHelper::load($useReset);
			}
		}

		// Plugin CSS
		$this->container->template->addCSS('media://plg_system_datacompliancecookie/css/datacompliancecookies.css', $this->container->mediaVersion);
	}

	/**
	 * Load the HTML template used by our JavaScript for either the cookie acceptance banner or the post-acceptance
	 * cookie controls (revoke consent or reconsider declining cookies).
	 *
	 * @param   JApplicationCms $app The CMS application we use to append the HTML output
	 *
	 * @return  void
	 *
	 * @since   1.1.0
	 */
	private function loadHtml($app)
	{
		// Prevent double inclusion of the HTML
		if ($this->haveIncludedHtml)
		{
			return;
		}

		$this->haveIncludedHtml = true;

		$this->loadLanguage();

		// Get the correct view template, depending on whether we have accepted cookies
		$template = 'plugin://system/datacompliancecookie/banner.php';

		$fileName = $this->container->template->parsePath($template, true);

		ob_start();
		include $fileName;
		$content = ob_get_clean();

		if ($this->hasCookiePreference)
		{
			$template = 'plugin://system/datacompliancecookie/controls.php';
			$fileName = $this->container->template->parsePath($template, true);

			ob_start();
			include $fileName;
			$content .= ob_get_clean();
		}

		// Append the parsed view template content to the application's HTML output
		$app->setBody($app->getBody() . $content);
	}

	/**
	 * Assign the current user to user groups depending on the cookie acceptance state.
	 *
	 * @return  void
	 *
	 * @since   1.1.0
	 */
	private function applyUserGroupAssignments(): void
	{
		// Note that permanent user group assignment IS NOT possible for guest (not logged in) users
		$user                     = $this->container->platform->getUser();
		$permanentGroupAssignment = ($this->params->get('permanentUserGroupAssignment', 0) == 1) && !$user->guest;
		$rejectGroup              = $this->params->get('cookiesRejectedUserGroup', 0);
		$acceptGroup              = $this->params->get('cookiesEnabledUserGroup', 0);

		// Do I have to do permanent user group assignment
		if ($permanentGroupAssignment && !$user->guest)
		{
			// TODO Permanent group assignment depending on $this->hasAcceptedCookies

		}

		if (!$this->hasAcceptedCookies)
		{
			/**
			 * Add the user to the selected "No cookies" user group.
			 *
			 * IMPORTANT! This must happen EVEN IF permanent assignment is requested since Joomla! does NOT reload the
			 * user group assignments until you log back in.
			 */
			if ($rejectGroup != 0)
			{
				DynamicGroups::addGroup($rejectGroup);
				// TODO Reload plugins in groups already loaded by the CMS
			}

			return;
		}

		/**
		 * Add the user to the selected "Accepted cookies" user group.
		 *
		 * IMPORTANT! This must happen EVEN IF permanent assignment is requested since Joomla! does NOT reload the
		 * user group assignments until you log back in.
		 */
		if ($acceptGroup != 0)
		{
			DynamicGroups::addGroup($acceptGroup);
			// TODO Reload plugins in groups already loaded by the CMS
		}
	}
}

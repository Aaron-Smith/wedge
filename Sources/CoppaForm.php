<?php
/**********************************************************************************
* Coppaform.php                                                                   *
***********************************************************************************
* SMF: Simple Machines Forum                                                      *
* Open-Source Project Inspired by Zef Hemel (zef@zefhemel.com)                    *
* =============================================================================== *
* Software Version:           SMF 2.0 RC5                                         *
* Software by:                Simple Machines (http://www.simplemachines.org)     *
* Copyright 2006-2010 by:     Simple Machines LLC (http://www.simplemachines.org) *
*           2001-2006 by:     Lewis Media (http://www.lewismedia.com)             *
* Support, News, Updates at:  http://www.simplemachines.org                       *
***********************************************************************************
* This program is free software; you may redistribute it and/or modify it under   *
* the terms of the provided license as published by Simple Machines LLC.          *
*                                                                                 *
* This program is distributed in the hope that it is and will be useful, but      *
* WITHOUT ANY WARRANTIES; without even any implied warranty of MERCHANTABILITY    *
* or FITNESS FOR A PARTICULAR PURPOSE.                                            *
*                                                                                 *
* See the "license.txt" file for details of the Simple Machines license.          *
* The latest version can always be found at http://www.simplemachines.org.        *
**********************************************************************************/

/**
 * This file deals with displaying a COPPA form to the user, for users who are under 13 to get completed by their parents.
 *
 * @package wedge
 */

if (!defined('SMF'))
	die('Hacking attempt...');

/**
 * Displays a COPPA compliance form to the user, which includes forum contact information.
 *
 * - Uses the Login language file and the Register template.
 * - Requires the user id in $_GET['member'].
 * - Queries the user to validate they not only exist, but they are currently flagged as requiring a COPPA form.
 * - If user requests to display the form ($_GET['form'] set), prepare the form, which includes collating contact details from $modSettings. If $_GET['dl'] is set, output the form as a forced download purely in text format, otherwise set up $context and push it to template_coppa_form.
 * - Otherwise display the general information.
 */

// This function will display the contact information for the forum, as well a form to fill in.
function CoppaForm()
{
	global $context, $modSettings, $txt;

	loadLanguage('Login');
	loadTemplate('Register');

	// No User ID??
	if (!isset($_GET['member']))
		fatal_lang_error('no_access', false);

	// Get the user details...
	$request = wesql::query('
		SELECT member_name
		FROM {db_prefix}members
		WHERE id_member = {int:id_member}
			AND is_activated = {int:is_coppa}',
		array(
			'id_member' => (int) $_GET['member'],
			'is_coppa' => 5,
		)
	);
	if (wesql::num_rows($request) == 0)
		fatal_lang_error('no_access', false);
	list ($username) = wesql::fetch_row($request);
	wesql::free_result($request);

	if (isset($_GET['form']))
	{
		// Some simple contact stuff for the forum.
		$context['forum_contacts'] = (!empty($modSettings['coppaPost']) ? $modSettings['coppaPost'] . '<br><br>' : '') . (!empty($modSettings['coppaFax']) ? $modSettings['coppaFax'] . '<br>' : '');
		$context['forum_contacts'] = !empty($context['forum_contacts']) ? $context['forum_name_html_safe'] . '<br>' . $context['forum_contacts'] : '';

		// Showing template?
		if (!isset($_GET['dl']))
		{
			// Shortcut for producing underlines.
			$context['ul'] = '<u>' . str_repeat('&nbsp;', 26) . '</u>';
			hideChrome();
			showSubTemplate('coppa_form');
			$context['page_title'] = $txt['coppa_form_title'];
			$context['coppa_body'] = str_replace(array('{PARENT_NAME}', '{CHILD_NAME}', '{USER_NAME}'), array($context['ul'], $context['ul'], $username), $txt['coppa_form_body']);
		}
		// Downloading.
		else
		{
			// The data.
			$ul = '                ';
			$crlf = "\r\n";
			$data = $context['forum_contacts'] . $crlf . $txt['coppa_form_address'] . ':' . $crlf . $txt['coppa_form_date'] . ':' . $crlf . $crlf . $crlf . $txt['coppa_form_body'];
			$data = str_replace(array('{PARENT_NAME}', '{CHILD_NAME}', '{USER_NAME}', '<br>', '<br />'), array($ul, $ul, $username, $crlf, $crlf), $data);

			// Send the headers.
			header('Connection: close');
			header('Content-Disposition: attachment; filename="approval.txt"');
			header('Content-Type: ' . ($context['browser']['is_ie'] || $context['browser']['is_opera'] ? 'application/octetstream' : 'application/octet-stream'));
			header('Content-Length: ' . count($data));

			echo $data;
			obExit(false);
		}
	}
	else
	{
		showSubTemplate('coppa');
		$context['page_title'] = $txt['coppa_title'];

		$context['coppa'] = array(
			'body' => str_replace('{MINIMUM_AGE}', $modSettings['coppaAge'], $txt['coppa_after_registration']),
			'many_options' => !empty($modSettings['coppaPost']) && !empty($modSettings['coppaFax']),
			'post' => empty($modSettings['coppaPost']) ? '' : $modSettings['coppaPost'],
			'fax' => empty($modSettings['coppaFax']) ? '' : $modSettings['coppaFax'],
			'phone' => empty($modSettings['coppaPhone']) ? '' : str_replace('{PHONE_NUMBER}', $modSettings['coppaPhone'], $txt['coppa_send_by_phone']),
			'id' => $_GET['member'],
		);
	}
}

?>
<?php
//##copyright##

$iaUsers = $iaCore->factory('users');

$iaDb->setTable(iaUsers::getTable());

if (iaView::REQUEST_JSON == $iaView->getRequestType())
{
	if (isset($_GET['email']))
	{
		$code = isset($_GET['code']) ? trim($_GET['code']) : false;
		$email = isset($_POST['email']) ? $_POST['email'] : (isset($_GET['email']) ? $_GET['email'] : '');
		$error = false;
		$message = array();

		if ($email)
		{
			if (!iaValidate::isEmail($email))
			{
				$error = true;
				$message = iaLanguage::get('error_email_incorrect');
			}
			$email = iaSanitize::sql($email);

			$member = $iaDb->row_bind(iaDb::ALL_COLUMNS_SELECTION, '`email` = :email AND `status` = :status', array('email' => $email, 'status' => iaCore::STATUS_ACTIVE));
			if (empty($member))
			{
				$error = true;
				$message = iaLanguage::get('error_no_member_email');
			}
			if (false !== $code && $member['sec_key'] != $code)
			{
				$error = true;
				$message = iaLanguage::get('confirmation_code_incorrect');
			}

			if (!$error && false === $code)
			{
				$mail = array();
				$token = $iaCore->util()->generateToken();
				$confirmationUrl = IA_URL . "forgot/?email=$email&code=$token";

				$iaMailer = $iaCore->factory('mailer');

				$iaMailer->load_template('password_restoration');
				$iaMailer->AddAddress($member['email'], $member['fullname']);
				$iaMailer->replace['{%FULLNAME%}'] = $member['fullname'];
				$iaMailer->replace['{%URL%}'] = $confirmationUrl;
				$iaMailer->replace['{%CODE%}'] = $token;
				$iaMailer->replace['{%EMAIL%}'] = $email;

				$iaMailer->Send();

				$message = iaLanguage::get('restore_pass_confirm');
				$iaDb->update(array('id' => $member['id'], 'sec_key' => $token), null, null, iaUsers::getTable());
			}
			elseif (!$error && $code)
			{
				$iaUsers->setNewPassword($member);

				$error = false;
				$message = iaLanguage::get('new_password_sent');
			}
		}
		elseif ($_POST && empty($_POST['email']))
		{
			$error = true;
			$message = iaLanguage::get('error_email_incorrect');
		}

		$iaView->assign('message', $message);
		$iaView->assign('result', !$error);
	}
}

if (iaView::REQUEST_HTML == $iaView->getRequestType())
{
	if (!$iaCore->get('members_enabled'))
	{
		return iaView::errorPage(iaView::ERROR_NOT_FOUND);
	}

	iaCore::util();

	if (iaUsers::hasIdentity())
	{
		iaUtil::go_to(IA_URL . 'profile/');
	}

	$memberId = null;
	$error = false;
	$messages = array();
	$itemData = array();

	if ('member_password_forgot' == $iaView->name())
	{
		$code = isset($_GET['code']) ? trim($_GET['code']) : false;
		$email = isset($_POST['email']) ? $_POST['email'] : (isset($_GET['email']) ? $_GET['email'] : '');
		$form = (false === $code) ? 'request' : 'confirm';

		if ($email)
		{
			if (!iaValidate::isEmail($email))
			{
				$error = true;
				$messages[] = iaLanguage::get('error_email_incorrect');
			}
			if ($form != 'confirm' && !iaValidate::isCaptchaValid())
			{
				$error = true;
				$messages[] = iaLanguage::get('confirmation_code_incorrect');
			}

			$member = $iaDb->row_bind(iaDb::ALL_COLUMNS_SELECTION, '`email` = :email AND `status` = :status', array('email' => $email, 'status' => iaCore::STATUS_ACTIVE), iaUsers::getTable());

			if (empty($member))
			{
				$error = true;
				$messages[] = iaLanguage::get('error_no_member_email');
			}
			if (false !== $code && $member['sec_key'] != $code)
			{
				$error = true;
				$messages[] = iaLanguage::get('confirmation_code_incorrect');
			}

			if (!$error && false === $code)
			{
				$token = iaUtil::generateToken();
				$confirmationUrl = IA_URL . 'forgot/?email=' . $email . '&code=' . $token;

				$iaMailer = $iaCore->factory('mailer');

				$iaMailer->load_template('password_restoration');
				$iaMailer->AddAddress($member['email'], $member['fullname']);
				$iaMailer->replace['{%FULLNAME%}'] = $member['fullname'];
				$iaMailer->replace['{%URL%}'] = $confirmationUrl;
				$iaMailer->replace['{%CODE%}'] = $token;
				$iaMailer->replace['{%EMAIL%}'] = $member['email'];

				$iaMailer->Send();

				$messages[] = iaLanguage::get('restore_pass_confirm');
				$iaDb->update(array('id' => $member['id'], 'sec_key' => $token), 0, 0, iaUsers::getTable());
				$form = 'confirm';
			}
			elseif (!$error && $code)
			{
				$error = false;
				$messages[] = iaLanguage::get('new_password_sent');
				$iaUsers->setNewPassword($member);
				$form = false;
			}
		}
		elseif ($_POST && empty($_POST['email']))
		{
			$error = true;
			$messages[] = iaLanguage::get('error_email_incorrect');

			if (!iaValidate::isCaptchaValid())
			{
				$error = true;
				$messages[] = iaLanguage::get('confirmation_code_incorrect');
			}
		}

		$iaView->assign('email', $email);
		$iaView->assign('form', $form);
	}
	else
	{
		$iaField = $iaCore->factory('field');
		$iaPlan = $iaCore->factory('plan', iaCore::FRONT);

		$iaView->assign('plans', $iaPlan->getPlans($iaUsers->getItemName()));
		$iaView->assign('sections', $iaField->filterByGroup($itemData, $iaUsers->getItemName()));

		if (isset($_POST['register']))
		{
			$fields = $iaField->filter($itemData, $iaUsers->getItemName());
			list($itemData, $error, $messages, ) = $iaField->parsePost($fields);

			if (!iaValidate::isCaptchaValid())
			{
				$error = true;
				$messages[] = iaLanguage::get('confirmation_code_incorrect');
			}

			if (isset($_POST['plan_id']))
			{
				$itemData[iaPlan::SPONSORED_PLAN_ID] = (int)$_POST['plan_id'];
			}

			if (isset($_POST['username']))
			{
				if ($iaDb->exists('`username` = :value', array('value' => $_POST['username']), iaUsers::getTable()))
				{
					$error = true;
					$messages[] = iaLanguage::get('username_already_exists');
				}
			}
			if (isset($_POST['email']))
			{
				if ($iaDb->exists('`email` = :value', array('value' => $_POST['email']), iaUsers::getTable()))
				{
					$error = true;
					$messages[] = iaLanguage::get('error_duplicate_email');
				}
			}

			if (!$error)
			{
				$itemData['password'] = iaUtil::checkPostParam('password');
				$itemData['disable_fields'] = isset($_POST['disable_fields']) ? (int)$_POST['disable_fields'] : 0;

				// check password
				if (!$itemData['disable_fields'])
				{
					if (!$itemData['password'])
					{
						$error = true;
						$messages[] = iaLanguage::get('error_password_empty');
					}
					else
					{
						if ($_POST['password'] != $_POST['password2'])
						{
							$error = true;
							$messages[] = iaLanguage::get('error_password_match');
						}
					}
				}
				else
				{
					$itemData['password'] = '';
				}
			}
			else
			{
				iaField::keepValues($itemData, $fields);
				$iaView->setMessages($messages, iaView::ERROR);
			}

			if (!$error)
			{
				$memberId = $iaUsers->register($itemData);

				if ($memberId)
				{
					$iaCore->factory('log')->write(iaLog::ACTION_CREATE, array('item' => 'member', 'name' => $itemData['fullname'], 'id' => $memberId, 'type' => iaCore::FRONT));
				}

				// process sponsored plan
				if ($memberId && isset($_POST['plan_id']) && is_numeric($_POST['plan_id']))
				{
					$plan = $iaPlan->getPlanById($_POST['plan_id']);

					$usergroup = $plan['usergroup'] ? $plan['usergroup'] : iaUsers::MEMBERSHIP_REGULAR;

					$iaDb->update(array('id' => $memberId, 'usergroup_id' => $usergroup), 0, 0, iaUsers::getTable());

					if ($plan['cost'] > 0)
					{
						$itemData['id'] = $memberId;
						$itemData['member_id'] = $memberId;

						if ($url = $iaPlan->prePayment($iaUsers->getItemName(), $itemData, $plan['id']))
						{
							iaUtil::redirect(iaLanguage::get('thanks'), iaLanguage::get('member_created'), $url);
						}
					}
				}
			}
		}
		elseif ('register_confirm' == $iaView->name())
		{
			if (!isset($_GET['email']) || !isset($_GET['key']))
			{
				return iaView::accessDenied();
			}

			$error = true;
			$result = $iaUsers->confirmation($_GET['email'], $_GET['key']);
			if ($result)
			{
				$messages[] = $iaCore->get('members_autoapproval') ? iaLanguage::get('reg_confirmed') : iaLanguage::get('reg_confirm_adm_approve');
				$error = false;

				$url = $iaCore->get('members_autoapproval') ? IA_URL . 'login/' : IA_URL;
				iaUtil::redirect(iaLanguage::get('reg_confirmation'), $messages, $url);
			}
			else
			{
				$messages[] = iaLanguage::get('confirmation_key_incorrect');
			}

			$iaView->assign('success', !$error);
		}
	}

	switch ($iaView->name())
	{
		case 'member_password_forgot':
			$template = 'forgot';

			break;

		case 'register_confirm':
			$template = 'registration-confirmation';

			break;

		default:
			if ($memberId)
			{
				$error = false;
				$template = 'registration-confirmation';
				$messages[] = iaLanguage::get('member_created');
				$iaView->assign('email', $itemData['email']);
			}
			else
			{
				$error = true;
				$template = 'registration';
				$iaView->assign('tmp', $itemData);
			}
	}

	$iaView->setMessages($messages, $error ? iaView::ERROR : iaView::SUCCESS);

	$iaView->display($template);
}

$iaDb->resetTable();
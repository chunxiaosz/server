<?php
/**
 * @copyright Copyright (c) 2017 Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OC\Collaboration\Collaborators;


use OCP\Collaboration\Collaborators\ISearchPlugin;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Collaboration\Collaborators\SearchResultType;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share;

class UserPlugin implements ISearchPlugin {
	/* @var bool */
	protected $shareWithGroupOnly;
	protected $shareeEnumeration;

	/** @var IConfig */
	private $config;
	/** @var IGroupManager */
	private $groupManager;
	/** @var IUserSession */
	private $userSession;
	/** @var IUserManager */
	private $userManager;

	public function __construct(IConfig $config, IUserManager $userManager, IGroupManager $groupManager, IUserSession $userSession) {
		$this->config = $config;

		$this->groupManager = $groupManager;
		$this->userSession = $userSession;
		$this->userManager = $userManager;

		$this->shareWithGroupOnly = $this->config->getAppValue('core', 'shareapi_only_share_with_group_members', 'no') === 'yes';
		$this->shareeEnumeration = $this->config->getAppValue('core', 'shareapi_allow_share_dialog_user_enumeration', 'yes') === 'yes';
	}

	public function search($search, $limit, $offset, ISearchResult $searchResult) {
		$result = ['wide' => [], 'exact' => []];
		$users = [];
		$hasMoreResults = false;

		$userGroups = [];
		if ($this->shareWithGroupOnly) {
			// Search in all the groups this user is part of
			$userGroups = $this->groupManager->getUserGroupIds($this->userSession->getUser());
			foreach ($userGroups as $userGroup) {
				$usersTmp = $this->groupManager->usersInGroup($userGroup, $search, $limit, $offset);
				foreach ($usersTmp as $uid => $user) {
					$users[$uid] = $user;
				}
			}
		} else {
			// Search in all users
			$usersTmp = $this->userManager->searchDisplayName($search, $limit, $offset);

			foreach ($usersTmp as $user) {
				$users[$user->getUID()] = $user;
			}
		}

		if (!$this->shareeEnumeration || sizeof($users) < $limit) {
			$hasMoreResults = true;
		}

		$foundUserById = false;
		$lowerSearch = strtolower($search);
		foreach ($users as $uid => $user) {
			if (strtolower($uid) === $lowerSearch || strtolower($user->getDisplayName()) === $lowerSearch) {
				if (strtolower($uid) === $lowerSearch) {
					$foundUserById = true;
				}
				$userData = [
					'label' => $user->getDisplayName(),
					'value' => [
						'shareType' => Share::SHARE_TYPE_USER,
						'shareWith' => $uid,
					],
				];
				if ($user->getEMailAddress()) {
					$userData['value']['emailAddress'] = $user->getEMailAddress();
				}
				$result['exact'][] = $userData;
			} else {
				$userData = [
					'label' => $user->getDisplayName(),
					'value' => [
						'shareType' => Share::SHARE_TYPE_USER,
						'shareWith' => $uid,
					],
				];
				if ($user->getEMailAddress()) {
					$userData['value']['emailAddress'] = $user->getEMailAddress();
				}
				$result['wide'][] = $userData;
			}
		}

		if ($offset === 0 && !$foundUserById) {
			// On page one we try if the search result has a direct hit on the
			// user id and if so, we add that to the exact match list
			$user = $this->userManager->get($search);
			if ($user instanceof IUser) {
				$addUser = true;

				if ($this->shareWithGroupOnly) {
					// Only add, if we have a common group
					$commonGroups = array_intersect($userGroups, $this->groupManager->getUserGroupIds($user));
					$addUser = !empty($commonGroups);
				}

				if ($addUser) {
					$userData = [
						'label' => $user->getDisplayName(),
						'value' => [
							'shareType' => Share::SHARE_TYPE_USER,
							'shareWith' => $user->getUID(),
						],
					];
					if ($user->getEMailAddress()) {
						$userData['value']['emailAddress'] = $user->getEMailAddress();
					}
					$result['exact'][] = $userData;
				}
			}
		}

		if (!$this->shareeEnumeration) {
			$result['wide'] = [];
		}

		$type = new SearchResultType('users');
		$searchResult->addResultSet($type, $result['wide'], $result['exact']);

		return $hasMoreResults;
	}
}
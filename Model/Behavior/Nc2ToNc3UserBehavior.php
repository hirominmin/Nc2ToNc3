<?php
/**
 * Nc2ToNc3UserBehavior
 *
 * @copyright Copyright 2014, NetCommons Project
 * @author Kohei Teraguchi <kteraguchi@commonsnet.org>
 * @link http://www.netcommons.org NetCommons Project
 * @license http://www.netcommons.org/license.txt NetCommons License
 */

App::uses('Nc2ToNc3UserBaseBehavior', 'Nc2ToNc3.Model/Behavior');

/**
 * Nc2ToNc3UserBehavior
 *
 */
class Nc2ToNc3UserBehavior extends Nc2ToNc3UserBaseBehavior {

/**
 * Get Log argument.
 *
 * @param Model $model Model using this behavior.
 * @param array $nc2User Nc2User data.
 * @return string Log argument
 */
	public function getLogArgument(Model $model, $nc2User) {
		return $this->__getLogArgument($nc2User);
	}

/**
 * Return whether it is waiting for approval.
 *
 * @param Model $model Model using this behavior.
 * @param array $nc2User Nc2User data.
 * @return bool True if data is waiting for approval.
 */
	public function isApprovalWaiting(Model $model, $nc2User) {
		return $this->__isApprovalWaiting($nc2User);
	}

/**
 * Check migration target
 *
 * @param Model $model Model using this behavior.
 * @param array $nc2User Nc2User data.
 * @return bool True if data is migration target.
 */
	public function isMigrationRow(Model $model, $nc2User) {
		// 承認待ち、本人確認待ちは移行しない（通知した承認用URLが違うため）
		// 移行して再度通知した方が良い気もする
		// とりあえず移行しとく
		if ($this->__isApprovalWaiting($nc2User)) {
			$message = __d('nc2_to_nc3', '%s is not active.Resend approval mail.', $this->__getLogArgument($nc2User));
			$this->_writeMigrationLog($message);
			return true;
		}

		return true;
	}

/**
 * Put existing id map
 *
 * @param Model $model Model using this behavior
 * @param array $nc2Users Nc2User data
 * @return void
 */
	public function putExistingIdMap(Model $model, $nc2Users) {
		$loginIds = Hash::extract($nc2Users, '{n}.Nc2User.login_id');

		/* @var $User User */
		$User = ClassRegistry::init('Users.User');
		$query = [
			'fields' => [
				'User.username',
				'User.id',
			],
			'conditions' => [
				'User.username' => $loginIds
			],
			'recursive' => -1
		];
		$nc3Users = $User->find('list', $query);

		foreach ($nc2Users as $nc2User) {
			$loginId = $nc2User['Nc2User']['login_id'];
			if (isset($nc3Users[$loginId])) {
				$this->_putIdMap($nc2User['Nc2User']['user_id'], $nc3Users[$loginId]);
			}
		}
	}

/**
 * Get Log argument.
 *
 * @param array $nc2User Nc2User data
 * @return string Log argument
 */
	private function __getLogArgument($nc2User) {
		return 'Nc2User ' .
			'user_id:' . $nc2User['Nc2User']['user_id'] .
			'handle:' . $nc2User['Nc2User']['handle'];
	}

/**
 * Return whether it is waiting for approval.
 *
 * @param array $nc2User Nc2User data.
 * @return bool True if data is waiting for approval.
 */
	private function __isApprovalWaiting($nc2User) {
		$active = $nc2User['Nc2User']['active_flag'];
		$isApprovalWaiting = !in_array($active, ['0', '1']);

		return $isApprovalWaiting;
	}

}

<?php
/**
 * Nc2ToNc3Room
 *
 * @copyright Copyright 2014, NetCommons Project
 * @author Kohei Teraguchi <kteraguchi@commonsnet.org>
 * @link http://www.netcommons.org NetCommons Project
 * @license http://www.netcommons.org/license.txt NetCommons License
 */

App::uses('Nc2ToNc3AppModel', 'Nc2ToNc3.Model');
App::uses('Current', 'NetCommons.Utility');

/**
 * Nc2ToNc3Room
 *
 * @see Nc2ToNc3BaseBehavior
 * @method void writeMigrationLog($message)
 * @method Model getNc2Model($tableName)
 * @method string getLanguageIdFromNc2()
 * @method string convertDate($date)
 * @method string convertLanguage($langDirName)
 * @method array saveMap($modelName, $idMap)
 * @method array getMap($nc2Id)
 *
 * @see Nc2ToNc3RoomBaseBehavior
 * @method string getDefaultRoleKeyFromNc2($nc2SpaceType)
 * @method array getNc3DefaultRolePermission()
 *
 * @see Nc2ToNc3RoomBehavior
 * @method string getLogArgument($nc2Page)
 * @method array getNc2RoomConditions()
 * @method array getNc2OtherLaguageRoomIdList($nc2Page)
 *
 */
class Nc2ToNc3Room extends Nc2ToNc3AppModel {

/**
 * Custom database table name, or null/false if no table association is desired.
 *
 * @var string
 * @link http://book.cakephp.org/2.0/en/models/model-attributes.html#usetable
 */
	public $useTable = false;

/**
 * List of behaviors to load when the model object is initialized. Settings can be
 * passed to behaviors by using the behavior name as index.
 *
 * @var array
 * @link http://book.cakephp.org/2.0/en/models/behaviors.html#using-behaviors
 */
	public $actsAs = ['Nc2ToNc3.Nc2ToNc3Room'];

/**
 * Migration method.
 *
 * @return bool True on success.
 */
	public function migrate() {
		$this->writeMigrationLog(__d('nc2_to_nc3', 'Room Migration start.'));

		// permalinkが同じデータを言語別のデータとして移行するが、
		// 言語ごとに移行しないと、parent_idが移行済みである保証ができない
		/* @var $Nc2Page AppModel */
		$Nc2Page = $this->getNc2Model('pages');
		$query = [
			'fields' => 'DISTINCT lang_dirname',
			'conditions' => $this->getNc2RoomConditions(),
			'recursive' => -1
		];
		$nc2Pages = $Nc2Page->find('all', $query);

		// Nc2Config.languageを優先する。
		// Nc3Room.activeがちょっと問題かも。（準備中を優先した方が良い？）
		foreach ($nc2Pages as $key => $nc2Page) {
			$nc2LangDirname = $nc2Page['Nc2Page']['lang_dirname'];

			// Communityの場合はNc2Page.lang_dirnameが空なのでスルー
			if (!$nc2LangDirname) {
				continue;
			}

			$nc3LaguageId = $this->convertLanguage($nc2LangDirname);
			if (!$nc3LaguageId) {
				unset($nc2Pages[$key]);
				continue;
			}

			if ($nc3LaguageId == $this->getLanguageIdFromNc2()) {
				unset($nc2Pages[$key]);
				array_unshift($nc2Pages, $nc2Page);
			}
		}

		foreach ($nc2Pages as $nc2Page) {
			if (!$this->__saveRoomFromNc2($nc2Page['Nc2Page']['lang_dirname'])) {
				return false;
			}
		}

		$this->writeMigrationLog(__d('nc2_to_nc3', 'Room Migration end.'));
		return true;
	}

/**
 * Save UserAttribue from Nc2.
 *
 * @param string $nc2LangDirName Nc2Page lang_dirname.
 * @return bool True on success.
 * @throws Exception
 */
	private function __saveRoomFromNc2($nc2LangDirName) {
		/* @var $Nc2Page AppModel */
		$Nc2Page = $this->getNc2Model('pages');
		$conditions = $this->getNc2RoomConditions();
		$conditions += [
			'Nc2Page.lang_dirname' => $nc2LangDirName
		];
		$query = [
			'conditions' => $conditions,
			'order' => [
				'Nc2Page.parent_id',
			],
			'recursive' => -1
		];
		$nc2Pages = $Nc2Page->find('all', $query);

		/* @var $Room Room */
		/* @var $RolesRoomsUser RolesRoomsUser */
		/* @var $Language Language */
		$Room = ClassRegistry::init('Rooms.Room');
		//$RolesRoomsUser = ClassRegistry::init('Rooms.RolesRoomsUser');
		$Language = ClassRegistry::init('M17n.Language');

		// 対応するルームが既存の処理について、対応させるデータが名前くらいしかない気がする。。。名前でマージして良いのか微妙なので保留
		//$this->saveExistingMap($nc2Pages);

		// is_originの値はsaveする前に現在の言語を切り替える処理が必要
		// @see https://github.com/NetCommons3/Rooms/blob/3.1.0/Model/Room.php#L516
		$nc3LanguageId = $this->getLanguageIdFromNc2();
		if (Current::read('Language.id') != $nc3LanguageId) {
			$currentLanguage = Current::read('Language');
			$language = $Language->findById($nc3LanguageId, null, null, -1);
			Current::write('Language', $language['Language']);
		}

		foreach ($nc2Pages as $nc2Page) {
			/*
			if (!$this->isMigrationRow($nc2User)) {
				continue;
			}*/

			$Room->begin();
			try {
				$data = $this->__generateNc3Data($nc2Page);
				if (!$data) {
					continue;
				}

				if (!($data = $Room->saveRoom($data))) {
					// 各プラグインのsave○○にてvalidation error発生時falseが返っていくるがrollbackしていないので、 ここでrollback
					$Room->rollback();

					// print_rはPHPMD.DevelopmentCodeFragmentに引っかかった。var_exportは大丈夫らしい。。。
					// @see https://phpmd.org/rules/design.html
					$message = $this->getLogArgument($nc2Page) . "\n" .
						var_export($Room->validationErrors, true);
					$this->writeMigrationLog($message);

					continue;
				}

				//$data = $this->__generateNc3RolesRoomsUser($data, $nc2Page);
				/*if (!$RolesRoomsUser->saveRolesRoomsUsersForRooms($data)) {
					// RolesRoomsUser::saveRolesRoomsUsersForRoomsではreturn falseなし
					continue;
				}*/

				$nc2RoomId = $nc2Page['Nc2Page']['room_id'];
				if ($this->getMap($nc2RoomId)) {
					continue;
				}

				$idMap = [
					$nc2RoomId => $Room->id
				];
				$this->saveMap('Room', $idMap);

				$Room->commit();

			} catch (Exception $ex) {
				if (isset($currentLanguage)) {
					Current::write('Language', $currentLanguage);
				}

				// NetCommonsAppModel::rollback()でthrowされるので、以降の処理は実行されない
				// $User::saveUser()でthrowされるとこの処理に入ってこない
				$Room->rollback($ex);
				throw $ex;
			}
		}

		if (isset($currentLanguage)) {
			Current::write('Language', $currentLanguage);
		}

		return true;
	}

/**
 * Generate nc3 data
 *
 * Data sample
 * data[Room][id]:
 * data[Room][space_id]:4
 * data[Room][root_id]:43
 * data[Room][parent_id]:3
 * data[Page][parent_id]:
 * data[RoomsLanguage][0][id]:
 * data[RoomsLanguage][0][room_id]:
 * data[RoomsLanguage][0][language_id]:2
 * data[RoomsLanguage][0][name]:sample
 * data[Room][default_participation]:0
 * data[Room][default_role_key]:general_user
 * data[Room][need_approval]:0
 * data[RoomRolePermission][content_publishable][chief_editor][value]:0
 * data[RoomRolePermission][content_publishable][chief_editor][value]:1
 * data[RoomRolePermission][content_publishable][editor][value]:0
 * data[RoomRolePermission][content_publishable][room_administrator][id]:
 * data[RoomRolePermission][content_publishable][chief_editor][id]:
 * data[RoomRolePermission][content_publishable][editor][id]:
 * data[RoomRolePermission][html_not_limited][room_administrator][value]:0
 * data[RoomRolePermission][html_not_limited][chief_editor][value]:0
 * data[RoomRolePermission][html_not_limited][editor][value]:0
 * data[RoomRolePermission][html_not_limited][room_administrator][id]:
 * data[RoomRolePermission][html_not_limited][chief_editor][id]:
 * data[RoomRolePermission][html_not_limited][editor][id]:
 * data[Room][active]:1
 *
 * @param array $nc2Page Nc2Page data.
 * @return array Nc3Room data.
 */
	private function __generateNc3Data($nc2Page) {
		$data = [];

		// 対応するルームが既存の場合（初回移行時にマッピングされる）、更新しない方が良いと思う。
		if ($this->getMap($nc2Page['Nc2Page']['room_id'])) {
			return $data;
		}

		// 言語別のmapデータが存在する場合は、Room.name,Room.created,Room.created_userを移行
		$otherLaguageRoomIds = $this->getNc2OtherLaguageRoomIdList($nc2Page);
		$otherLaguageMap = $this->getMap($otherLaguageRoomIds);
		if ($otherLaguageMap) {
			return $this->__generateNc3ExistsRooms($nc2Page, $otherLaguageMap);
		}

		return $this->__generateNc3NotExistsRooms($nc2Page);
	}

/**
 * Generate Nc3RoomsLanguage data.
 *
 * @param array $nc2Page Nc2Page data.
 * @param array $otherLaguageMap Other laguage map data.
 * @return array Nc3Room data.
 */
	private function __generateNc3ExistsRooms($nc2Page, $otherLaguageMap) {
		/* @var $Room Room */
		$Room = ClassRegistry::init('Rooms.Room');

		// 別言語Mapデータは複数あっても、対応するNc3Room.idは1つ
		$nc3Room = current($otherLaguageMap);
		$data = $Room->findById($nc3Room['Room']['id']);
		$nc3LaguageId = $this->convertLanguage($nc2Page['Nc2Page']['lang_dirname']);
		foreach ($data['RoomsLanguage'] as $key => $nc3RoomLaguage) {
			if ($nc3RoomLaguage['language_id'] != $nc3LaguageId) {
				continue;
			}

			$data['RoomsLanguage'][$key] = $this->__generateNc3RoomsLanguage($nc3RoomLaguage, $nc2Page);
		}

		// Space::createRoomでデータを作成する際、page_layout_permittedも初期値nullでsetされる。
		// しかしながら、ルームの登録画面からは、page_layout_permittedがPOSTされないっぽい。 データがあると、Validationに引っかかる。
		// @see https://github.com/NetCommons3/Rooms/blob/3.1.0/View/Elements/Rooms/edit_form.ctp
		// @see https://github.com/NetCommons3/Rooms/blob/3.1.0/Model/Room.php#L226-L231
		unset($data['Room']['page_layout_permitted']);

		return $data;
	}

/**
 * Generate Nc3RoomsLanguage data.
 *
 * @param array $nc2Page Nc2Page data.
 * @return array Nc3Room data.
 */
	private function __generateNc3NotExistsRooms($nc2Page) {
		/* @var $Room Room */
		$Room = ClassRegistry::init('Rooms.Room');
		$spaces = $Room->getSpaces();
		$nc2SpaceType = $nc2Page['Nc2Page']['space_type'];

		/* @var $Space Space */
		if ($nc2SpaceType == '1') {
			$Space = ClassRegistry::init('PublicSpace.PublicSpace');
			$spaceId = Space::PUBLIC_SPACE_ID;
			$needApproval = '1';

		}
		if ($nc2SpaceType == '2') {
			$Space = ClassRegistry::init('CommunitySpace.CommunitySpace');
			$spaceId = Space::COMMUNITY_SPACE_ID;
			$needApproval = '0';
		}

		$parenId = $spaces[$spaceId]['Space']['room_id_root'];
		$map = $this->getMap($nc2Page['Nc2Page']['parent_id']);
		if ($map) {
			$parenId = $map['Room']['id'];
		}

		$defaultRoleKey = $spaces[$spaceId]['Room']['default_role_key'];
		if ($nc2Page['Nc2Page']['default_entry_flag'] == '1') {
			$defaultRoleKey = $this->getDefaultRoleKeyFromNc2($nc2SpaceType);
		}

		/* @var $Nc2ToNc3User Nc2ToNc3User */
		$Nc2ToNc3User = ClassRegistry::init('Nc2ToNc3.Nc2ToNc3User');
		$data = [
			'space_id' => $spaceId,
			'root_id' => $spaces[$spaceId]['Space']['room_id_root'],	// 使ってないっぽい
			'parent_id' => $parenId,
			'active' => $nc2Page['Nc2Page']['display_flag'],
			'default_role_key' => $defaultRoleKey,
			'need_approval' => $needApproval,
			'default_participation' => $nc2Page['Nc2Page']['default_entry_flag'],
			'created' => $this->convertDate($nc2Page['Nc2Page']['insert_time']),
			'created_user' => $Nc2ToNc3User->getCreatedUser($nc2Page['Nc2Page'])
		];
		$data = $Space->createRoom($data);

		// Space::createRoomでデータを作成する際、page_layout_permittedも初期値nullでsetされる。
		// しかしながら、ルームの登録画面からは、page_layout_permittedがPOSTされないっぽい。 データがあると、Validationに引っかかる。
		// @see https://github.com/NetCommons3/Rooms/blob/3.1.0/View/Elements/Rooms/edit_form.ctp
		// @see https://github.com/NetCommons3/Rooms/blob/3.1.0/Model/Room.php#L226-L231
		unset(
			$data['Room']['page_layout_permitted'],
			$Room->data['Room']['page_layout_permitted']
		);

		foreach ($data['RoomsLanguage'] as $key => $nc3RoomLaguage) {
			$data['RoomsLanguage'][$key] = $this->__generateNc3RoomsLanguage($nc3RoomLaguage, $nc2Page);
		}
		$data['RoomRolePermission'] = $this->getNc3DefaultRolePermission();
		$data['PluginsRoom'] = $this->__generateNc3PluginsRoom($nc2Page);

		return $data;
	}

/**
 * Generate Nc3RoomsLanguage data.
 *
 * @param array $nc3RoomLanguage Nc3RoomsLanguage data.
 * @param array $nc2Page Nc2Page data.
 * @return array Nc3RoomsLanguage data.
 */
	private function __generateNc3RoomsLanguage($nc3RoomLanguage, $nc2Page) {
		/* @var $Nc2ToNc3User Nc2ToNc3User */
		$Nc2ToNc3User = ClassRegistry::init('Nc2ToNc3.Nc2ToNc3User');
		$nc3RoomLanguage['name'] = $nc2Page['Nc2Page']['page_name'];
		$nc3RoomLanguage['created'] = $this->convertDate($nc2Page['Nc2Page']['insert_time']);
		$nc3RoomLanguage['created_user'] = $Nc2ToNc3User->getCreatedUser($nc2Page['Nc2Page']);

		return $nc3RoomLanguage;
	}

/**
 * Generate Nc3PluginsRoom data.
 *
 * @param array $nc2Page Nc2Page data.
 * @return array Nc3PluginsRoom data.
 */
	private function __generateNc3PluginsRoom($nc2Page) {
		/* @var $Nc2PagesModulesLink AppModel */
		$Nc2PagesModulesLink = $this->getNc2Model('pages_modules_link');
		$nc2PageModuleLinks = $Nc2PagesModulesLink->findAllByRoomId(
			$nc2Page['Nc2Page']['room_id'],
			'module_id',
			null,
			-1
		);

		/* @var $Nc2ToNc3Plugin Nc2ToNc3Plugin */
		$Nc2ToNc3Plugin = ClassRegistry::init('Nc2ToNc3.Nc2ToNc3Plugin');
		$map = $Nc2ToNc3Plugin->getMap();
		$notExistsKeys = [
			'auth'
		];
		$nc3PluginsRoom['plugin_key'] = [];
		foreach ($nc2PageModuleLinks as $nc2PageModuleLink) {
			$nc2ModuleId = $nc2PageModuleLink['Nc2PagesModulesLink']['module_id'];
			if (!isset($map[$nc2ModuleId]['Plugin']['key']) ||
				in_array($map[$nc2ModuleId]['Plugin']['key'], $notExistsKeys)
			) {
				continue;
			}

			$nc3PluginsRoom['plugin_key'][] = $map[$nc2ModuleId]['Plugin']['key'];
		}

		return $nc3PluginsRoom;
	}

/**
 * Generate Nc3PluginsRoom data.
 *
 * @param array $nc3Room Nc3Room data.
 * @param array $nc2Page Nc2Page data.
 * @return array Nc3PluginsRoom data.
 */
	private function __generateNc3RolesRoomsUser($nc3Room, $nc2Page) {
		/* @var $Nc2PagesUsersLink AppModel */
		$Nc2PagesUsersLink = $this->getNc2Model('pages_users_link');
		$nc2PagesUsersLink = $Nc2PagesUsersLink->findAllByRoomId(
				$nc2Page['Nc2Page']['room_id'],
				null,
				null,
				-1
		);

		return $nc2PagesUsersLink;
	}

}

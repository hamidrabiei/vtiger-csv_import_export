<?php

/* +***********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 * *********************************************************************************** */

class CsvImport_Utils_Helper {

	static $AUTO_MERGE_NONE = 0;
	static $AUTO_MERGE_IGNORE = 1;
	static $AUTO_MERGE_OVERWRITE = 2;
	static $AUTO_MERGE_MERGEFIELDS = 3;

	static $supportedFileEncoding = array(
		'UTF-8'=>'UTF-8',
		'ISO-8859-1'=>'ISO-8859-1',
		'Windows-1250'=>'Windows-1250',
		'Windows-1251'=>'Windows-1251',
		'Windows-1252'=>'Windows-1252',
		'Windows-1253'=>'Windows-1253',
		'Windows-1254'=>'Windows-1254',
		'Windows-1255'=>'Windows-1255',
		'Windows-1256'=>'Windows-1256',
		'Windows-1257'=>'Windows-1257',
		'Windows-1258'=>'Windows-1258',
		);
	static $supportedDelimiters = array(','=>'comma');
	static $supportedFileExtensions = array('csv');

	public function getSupportedFileExtensions() {
		return self::$supportedFileExtensions;
	}

	public function getSupportedFileEncoding() {
		return self::$supportedFileEncoding;
	}

	public function getSupportedDelimiters() {
		return self::$supportedDelimiters;
	}

	public static function getAutoMergeTypes() {
		return array(
			self::$AUTO_MERGE_IGNORE => 'Skip',
			self::$AUTO_MERGE_OVERWRITE => 'Overwrite',
			self::$AUTO_MERGE_MERGEFIELDS => 'Merge');
	}

	public static function getMaxUploadSize() {
		global $upload_maxsize;
		return $upload_maxsize;
	}

	public static function getImportDirectory() {
		global $import_dir;
		$importDir = dirname(__FILE__). '/../../../'.$import_dir;
		return $importDir;
	}

	public static function getImportFilePath($user) {
		$importDirectory = self::getImportDirectory();
		return $importDirectory. "IMPORT_".$user->id;
	}


	public static function getFileReaderInfo($type) {
		$configReader = new CsvImport_Config_Model();
		$importTypeConfig = $configReader->get('importTypes');
		if(isset($importTypeConfig[$type])) {
			return $importTypeConfig[$type];
		}
		return null;
	}

	public static function getFileReader($request, $user) {
		$fileReaderInfo = self::getFileReaderInfo($request->get('type'));
		if(!empty($fileReaderInfo)) {
			require_once $fileReaderInfo['classpath'];
			$fileReader = new $fileReaderInfo['reader'] ($request, $user);
		} else {
			$fileReader = null;
		}
		return $fileReader;
	}

	public static function getDbTableName($user) {
		$configReader = new CsvImport_Config_Model();
		$userImportTablePrefix = $configReader->get('userImportTablePrefix');

        $tableName = $userImportTablePrefix;
        if(method_exists($user, 'getId')){
            $tableName .= $user->getId();
        } else {
            $tableName .= $user->id;
        }
        return $tableName;
	}

	public static function showErrorPage($errorMessage, $errorDetails=false, $customActions=false) {
		$viewer = new Vtiger_Viewer();

		$viewer->assign('ERROR_MESSAGE', $errorMessage);
		$viewer->assign('ERROR_DETAILS', $errorDetails);
		$viewer->assign('CUSTOM_ACTIONS', $customActions);
		$viewer->assign('MODULE','Import');

		$viewer->view('ImportError.tpl', 'CsvImport');
	}

	public static function showImportLockedError($lockInfo) {

		$errorMessage = vtranslate('CSV_ERR_MODULE_IMPORT_LOCKED', 'CsvImport');
		$errorDetails = array(vtranslate('CSV_LBL_MODULE_NAME', 'CsvImport') => getTabModuleName($lockInfo['tabid']),
							vtranslate('CSV_LBL_USER_NAME', 'CsvImport') => getUserFullName($lockInfo['userid']),
							vtranslate('CSV_LBL_LOCKED_TIME', 'CsvImport') => $lockInfo['locked_since']);

		self::showErrorPage($errorMessage, $errorDetails);
	}

	public static function showImportTableBlockedError($moduleName, $user) {

		$errorMessage = vtranslate('CSV_ERR_UNIMPORTED_RECORDS_EXIST', 'CsvImport');
		$customActions = array('CSV_LBL_CLEAR_DATA' => "location.href='index.php?module={$moduleName}&view=Import&mode=clearCorruptedData'");

		self::showErrorPage($errorMessage, '', $customActions);
	}

	public static function isUserImportBlocked($user) {
		$adb = PearDatabase::getInstance();
		$tableName = self::getDbTableName($user);

		if(Vtiger_Utils::CheckTable($tableName)) {
			$result = $adb->query('SELECT 1 FROM '.$tableName.' WHERE status = '.  CsvImport_Data_Action::$IMPORT_RECORD_NONE);
			if($adb->num_rows($result) > 0) {
				return true;
			}
		}
		return false;
	}

	public static function clearUserImportInfo($user) {
		$adb = PearDatabase::getInstance();
		$tableName = self::getDbTableName($user);

		$adb->query('DROP TABLE IF EXISTS '.$tableName);
		CsvImport_Lock_Action::unLock($user);
		CsvImport_Queue_Action::removeForUser($user);
	}

	public static function getAssignedToUserList($module) {
		$cache = Vtiger_Cache::getInstance();
		if($cache->getUserList($module,$current_user->id)){
			return $cache->getUserList($module,$current_user->id);
		} else {
			$userList = get_user_array(FALSE, "Active", $current_user->id);
			$cache->setUserList($module,$userList,$current_user->id);
			return $userList;
		}
	}

	public static function getAssignedToGroupList($module) {
		$cache = Vtiger_Cache::getInstance();
		if($cache->getGroupList($module,$current_user->id)){
			return $cache->getGroupList($module,$current_user->id);
		} else {
			$groupList = get_group_array(FALSE, "Active", $current_user->id);
			$cache->setGroupList($module,$groupList,$current_user->id);
			return $groupList;
		}
	}

	public static function hasAssignPrivilege($moduleName, $assignToUserId) {
		$assignableUsersList = self::getAssignedToUserList($moduleName);
		if(array_key_exists($assignToUserId, $assignableUsersList)) {
			return true;
		}
		$assignableGroupsList = self::getAssignedToGroupList($moduleName);
		if(array_key_exists($assignToUserId, $assignableGroupsList)) {
			return true;
		}
		return false;
	}

	public static function validateFileUpload($request) {
		$current_user = Users_Record_Model::getCurrentUserModel();

		$uploadMaxSize = self::getMaxUploadSize();
		$importDirectory = self::getImportDirectory();
		$temporaryFileName = self::getImportFilePath($current_user);

		if($_FILES['import_file']['error']) {
			$request->set('error_message', self::fileUploadErrorMessage($_FILES['import_file']['error']));
			return false;
		}
		if(!is_uploaded_file($_FILES['import_file']['tmp_name'])) {
			$request->set('error_message', vtranslate('CSV_LBL_FILE_UPLOAD_FAILED', 'CsvImport'));
			return false;
		}
		if ($_FILES['import_file']['size'] > $uploadMaxSize) {
			$request->set('error_message', vtranslate('CSV_LBL_IMPORT_ERROR_LARGE_FILE', 'CsvImport').
												 $uploadMaxSize.' '.vtranslate('CSV_LBL_IMPORT_CHANGE_UPLOAD_SIZE', 'CsvImport'));
			return false;
		}
		if(!is_writable($importDirectory)) {
			$request->set('error_message', vtranslate('CSV_LBL_IMPORT_DIRECTORY_NOT_WRITABLE', 'CsvImport'));
			return false;
		}

		$fileCopied = move_uploaded_file($_FILES['import_file']['tmp_name'], $temporaryFileName);
		if(!$fileCopied) {
			$request->set('error_message', vtranslate('CSV_LBL_IMPORT_FILE_COPY_FAILED', 'CsvImport'));
			return false;
		}
		$fileReader = CsvImport_Utils_Helper::getFileReader($request, $current_user);

		if($fileReader == null) {
			$request->set('error_message', vtranslate('CSV_LBL_INVALID_FILE', 'CsvImport'));
			return false;
		}
		
		$hasHeader = $fileReader->hasHeader();
		$firstRow = $fileReader->getFirstRowData($hasHeader);

		if($firstRow === false) {
			$request->set('error_message', vtranslate('CSV_LBL_NO_ROWS_FOUND', 'CsvImport'));
			return false;
		}
		return true;
	}

	static function fileUploadErrorMessage($error_code) {
		switch ($error_code) {
			case 1:
				return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
			case 2:
				return 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form';
			case 3:
				return 'The uploaded file was only partially uploaded';
			case 4:
				return 'No file was uploaded';
			case 6:
				return 'Missing a temporary folder';
			case 7:
				return 'Failed to write file to disk';
			case 8:
				return 'File upload stopped by extension';
			default:
				return 'Unknown upload error';
		}
	}
}

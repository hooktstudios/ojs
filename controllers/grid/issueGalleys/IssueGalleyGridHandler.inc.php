<?php

/**
 * @file controllers/grid/issueGalleys/IssueGalleyGridHandler.inc.php
 *
 * Copyright (c) 2014 Simon Fraser University Library
 * Copyright (c) 2000-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class IssueGalleyGridHandler
 * @ingroup issue_galley
 *
 * @brief Handle issues grid requests.
 */

import('lib.pkp.classes.controllers.grid.GridHandler');
import('controllers.grid.issueGalleys.IssueGalleyGridRow');

class IssueGalleyGridHandler extends GridHandler {
	/**
	 * Constructor
	 */
	function IssueGalleyGridHandler() {
		parent::GridHandler();
		$this->addRoleAssignment(
			array(ROLE_ID_EDITOR, ROLE_ID_MANAGER),
			array(
				'fetchGrid', 'fetchRow', 'saveSequence',
				'add', 'edit', 'upload', 'download', 'update', 'delete'
			)
		);
	}


	//
	// Implement template methods from PKPHandler
	//
	/**
	 * @copydoc PKPHandler::authorize()
	 */
	function authorize($request, &$args, $roleAssignments) {
		import('lib.pkp.classes.security.authorization.PkpContextAccessPolicy');
		$this->addPolicy(new PkpContextAccessPolicy($request, $roleAssignments));

		import('classes.security.authorization.OjsIssueRequiredPolicy');
		$this->addPolicy(new OjsIssueRequiredPolicy($request, $args));

		// If a signoff ID was specified, authorize it.
		if ($request->getUserVar('issueGalleyId')) {
			import('classes.security.authorization.OjsIssueGalleyRequiredPolicy');
			$this->addPolicy(new OjsIssueGalleyRequiredPolicy($request, $args));
		}

		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * @copydoc GridHandler::getDataElementSequence()
	 */
	function getDataElementSequence($issueGalley) {
		return $issueGalley->getSequence();
	}

	/**
	 * @copydoc GridHandler::setDataElementSequence()
	 */
	function setDataElementSequence($request, $rowId, &$issueGalley, $newSequence) {
		$issueGalleyDao = DAORegistry::getDAO('IssueGalleyDAO'); /* @var $issueGalleyDao IssueGalleyDAO */
		$issueGalley->setSequence($newSequence);
		$issueGalleyDao->updateObject($issueGalley);
	}

	/**
	 * @copydoc GridHandler::addFeatures()
	 */
	function initFeatures($request, $args) {
		import('lib.pkp.classes.controllers.grid.feature.OrderGridItemsFeature');
		return array(new OrderGridItemsFeature());
	}

	/**
	 * @copydoc GridDataProvider::getRequestArgs()
	 */
	function getRequestArgs() {
		$issue = $this->getAuthorizedContextObject(ASSOC_TYPE_ISSUE);
		$issueGalley = $this->getAuthorizedContextObject(ASSOC_TYPE_ISSUE_GALLEY);
		$requestArgs = (array) parent::getRequestArgs();
		$requestArgs['issueId'] = $issue->getId();
		if ($issueGalley) $requestArgs['issueGalleyId'] = $issueGalley->getId();
		return $requestArgs;
	}

	/**
	 * @copydoc PKPHandler::initialize()
	 */
	function initialize($request, $args) {
		parent::initialize($request, $args);

		AppLocale::requireComponents(LOCALE_COMPONENT_APP_EDITOR, LOCALE_COMPONENT_PKP_SUBMISSION);

		// Add action
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		$this->addAction(
			new LinkAction(
				'add',
				new AjaxModal(
					$router->url($request, null, null, 'add', null, $this->getRequestArgs() + array('gridId' => $this->getId())),
					__('grid.action.addIssueGalley'),
					'modal_add'
				),
				__('grid.action.addIssueGalley'),
				'add_category'
			)
		);

		// Grid columns.
		import('controllers.grid.issueGalleys.IssueGalleyGridCellProvider');
		$issueGalleyGridCellProvider = new IssueGalleyGridCellProvider();

		// Issue identification
		$this->addColumn(
			new GridColumn(
				'label',
				'submission.layout.galleyLabel',
				null,
				'controllers/grid/gridCell.tpl',
				$issueGalleyGridCellProvider
			)
		);

		// Language, if more than one is supported
		$journal = $request->getJournal();
		if (count($journal->getSupportedLocaleNames())>1) {
			$this->addColumn(
				new GridColumn(
					'locale',
					'common.language',
					null,
					'controllers/grid/gridCell.tpl',
					$issueGalleyGridCellProvider
				)
			);
		}

		// Public ID, if enabled
		if ($journal->getSetting('enablePublicGalleyId')) {
			$this->addColumn(
				new GridColumn(
					'publicGalleyId',
					'submission.layout.publicGalleyId',
					null,
					'controllers/grid/gridCell.tpl',
					$issueGalleyGridCellProvider
				)
			);
		}
	}

	/**
	 * Get the row handler - override the default row handler
	 * @return IssueGalleyGridRow
	 */
	function getRowInstance() {
		$issue = $this->getAuthorizedContextObject(ASSOC_TYPE_ISSUE);
		return new IssueGalleyGridRow($issue->getId());
	}

	//
	// Public operations
	//
	/**
	 * An action to add a new issue
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function add($args, $request) {
		// Calling editIssueData with an empty ID will add
		// a new issue.
		return $this->edit($args, $request);
	}

	/**
	 * An action to edit a issue galley
	 * @param $args array
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function edit($args, $request) {
		$issue = $this->getAuthorizedContextObject(ASSOC_TYPE_ISSUE);
		$issueGalley = $this->getAuthorizedContextObject(ASSOC_TYPE_ISSUE_GALLEY);

		import('controllers.grid.issues.form.IssueGalleyForm');
		$issueGalleyForm = new IssueGalleyForm($request, $issue, $issueGalley);
		$issueGalleyForm->initData($request);
		$json = new JSONMessage(true, $issueGalleyForm->fetch($request));
		return $json->getString();
	}

	/**
	 * An action to upload an issue galley file.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function upload($args, $request) {
		$user = $request->getUser();

		import('lib.pkp.classes.file.TemporaryFileManager');
		$temporaryFileManager = new TemporaryFileManager();
		$temporaryFile = $temporaryFileManager->handleUpload('uploadedFile', $user->getId());
		if ($temporaryFile) {
			$json = new JSONMessage(true);
			$json->setAdditionalAttributes(array(
				'temporaryFileId' => $temporaryFile->getId()
			));
		} else {
			$json = new JSONMessage(false, __('common.uploadFailed'));
		}

		return $json->getString();
	}

	/**
	 * An action to download an issue galley
	 * @param $args array
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function download($args, $request) {
		$issue = $this->getAuthorizedContextObject(ASSOC_TYPE_ISSUE);
		$issueGalley = $this->getAuthorizedContextObject(ASSOC_TYPE_ISSUE_GALLEY);
		import('classes.file.IssueFileManager');
		$issueFileManager = new IssueFileManager($issue->getId());
		return $issueFileManager->downloadFile($issueGalley->getFileId());
	}

	/**
	 * Update a issue
	 * @param $args array
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function update($args, $request) {
		$issue = $this->getAuthorizedContextObject(ASSOC_TYPE_ISSUE);
		$issueGalley = $this->getAuthorizedContextObject(ASSOC_TYPE_ISSUE_GALLEY);

		import('controllers.grid.issues.form.IssueGalleyForm');
		$issueGalleyForm = new IssueGalleyForm($request, $issue, $issueGalley);
		$issueGalleyForm->readInputData();

		if ($issueGalleyForm->validate($request)) {
			$issueId = $issueGalleyForm->execute($request);
			return DAO::getDataChangedEvent($issueId);
		} else {
			$json = new JSONMessage(false);
			return $json->getString();
		}
	}

	/**
	 * Removes an issue galley
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function delete($args, $request) {
		$issueGalleyDao = DAORegistry::getDAO('IssueGalleyDAO');
		$issueGalley = $this->getAuthorizedContextObject(ASSOC_TYPE_ISSUE_GALLEY);
		$issueGalleyId = $issueGalley->getId();
		$issueGalleyDao->deleteObject($issueGalley);
		return DAO::getDataChangedEvent();
	}

	/**
	 * @copydoc GridHandler::loadData
	 */
	function loadData($request, $filter) {
		$issue = $this->getAuthorizedContextObject(ASSOC_TYPE_ISSUE);
		$issueGalleyDao = DAORegistry::getDAO('IssueGalleyDAO');
		return $issueGalleyDao->getByIssueId($issue->getId());
	}
}

?>

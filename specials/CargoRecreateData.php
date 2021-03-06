<?php
/**
 * Displays an interface to let users recreate data via the Cargo
 * extension.
 *
 * @author Yaron Koren
 * @ingroup Cargo
 */

class CargoRecreateData extends UnlistedSpecialPage {
	public $mTemplateTitle;
	public $mTableName;
	public $mIsDeclared;

	function __construct( $templateTitle, $tableName, $isDeclared ) {
		parent::__construct( 'RecreateData', 'recreatecargodata' );
		$this->mTemplateTitle = $templateTitle;
		$this->mTableName = $tableName;
		$this->mIsDeclared = $isDeclared;
	}

	function execute( $query = null ) {
		global $wgUser, $wgScriptPath, $cgScriptPath;

		// Check permissions.
		if ( !$wgUser->isAllowed( 'recreatecargodata' ) ) {
			$this->displayRestrictionError();
			return;
		}

		$out = $this->getOutput();

		$this->setHeaders();

		$cdb = CargoUtils::getDB();
		$tableExists = $cdb->tableExists( $this->mTableName );
		if ( !$tableExists ) {
			$out->setPageTitle( $this->msg( 'cargo-createdatatable' )->parse() );
		}

		// Disable page if "replacement table" exists.
		$possibleReplacementTable = $this->mTableName . '__NEXT';
		if ( $cdb->tableExists( $possibleReplacementTable ) ) {
			$text = $this->msg( 'cargo-recreatedata-replacementexists', $this->mTableName, $possibleReplacementTable )->parse();
			$ctPage = SpecialPageFactory::getPage( 'CargoTables' );
			$ctURL = $ctPage->getTitle()->getFullURL();
			$viewURL = $ctURL . '/' . $this->mTableName;
			$viewURL .= strpos( $viewURL, '?' ) ? '&' : '?';
			$viewURL .= "_replacement";
			$viewReplacementText = $this->msg( 'cargo-cargotables-viewreplacementlink' )->parse();

			$text .= ' (' . Xml::element( 'a', array( 'href' => $viewURL ), $viewReplacementText ) . ')';
			$out->addHTML( $text );
			return true;
		}

		if ( empty( $this->mTemplateTitle ) ) {
			// No template.
			// TODO - show an error message.
			return true;
		}

		$out->addModules( 'ext.cargo.recreatedata' );

		$templateData = array();
		$dbw = wfGetDB( DB_MASTER );

		$templateData[] = array(
			'name' => $this->mTemplateTitle->getText(),
			'numPages' => $this->getNumPagesThatCallTemplate( $dbw, $this->mTemplateTitle )
		);

		if ( $this->mIsDeclared ) {
			// Get all attached templates.
			$res = $dbw->select( 'page_props',
				array(
					'pp_page'
				),
				array(
					'pp_value' => $this->mTableName,
					'pp_propname' => 'CargoAttachedTable'
				)
			);
			while ( $row = $dbw->fetchRow( $res ) ) {
				$templateID = $row['pp_page'];
				$attachedTemplateTitle = Title::newFromID( $templateID );
				$numPages = $this->getNumPagesThatCallTemplate( $dbw, $attachedTemplateTitle );
				$attachedTemplateName = $attachedTemplateTitle->getText();
				$templateData[] = array(
					'name' => $attachedTemplateName,
					'numPages' => $numPages
				);
			}
		}

		$ct = SpecialPage::getTitleFor( 'CargoTables' );
		$viewTableURL = $ct->getInternalURL() . '/' . $this->mTableName;

		// Store all the necesssary data on the page.
		$text = Html::element( 'div', array(
				'hidden' => 'true',
				'id' => 'recreateDataData',
				// These two variables are not data-
				// specific, but this seemed like the
				// easiest way to pass them over without
				// interfering with any other pages.
				// (Is this the best way to get the
				// API URL?)
				'apiurl' => $wgScriptPath . "/api.php",
				'cargoscriptpath' => $cgScriptPath,
				'tablename' => $this->mTableName,
				'isdeclared' => $this->mIsDeclared,
				'viewtableurl' => $viewTableURL
			), json_encode( $templateData ) );

		// Simple form.
		$text .= '<div id="recreateDataCanvas">' . "\n";
		if ( $tableExists ) {
			// Possibly disable checkbox, to avoid problems if the
			// DB hasn't been updated for version 1.5+.
			$indexExists = $dbw->indexExists( 'cargo_tables', 'cargo_tables_template_id' );
			if ( $indexExists ) {
				$text .= '<p><em>The checkbox intended to go here is temporarily disabled; please run <tt>update.php</tt> to see it.</em></p>';
			} else {
				$text .= Html::rawElement( 'p', null, Html::check( 'createReplacement', true, array( 'id' => 'createReplacement' ) ) .
					' ' . $this->msg( 'cargo-recreatedata-createreplacement' )->parse() );
			}
		}
		$msg = $tableExists ? 'cargo-recreatedata-desc' : 'cargo-recreatedata-createdata';
		$text .= Html::element( 'p', null, $this->msg( $msg )->parse() );
		$text .= Html::element( 'button', array( 'id' => 'cargoSubmit' ), $this->msg( 'ok' )->parse() );
		$text .= "\n</div>";

		$out->addHTML( $text );

		return true;
	}

	function getNumPagesThatCallTemplate( $dbw, $templateTitle ) {
		$res = $dbw->select(
			array( 'page', 'templatelinks' ),
			'COUNT(*) AS total',
			array(
				"tl_from=page_id",
				"tl_namespace" => $templateTitle->getNamespace(),
				"tl_title" => $templateTitle->getDBkey() ),
			__METHOD__,
			array()
		);
		$row = $dbw->fetchRow( $res );
		return intval( $row['total'] );
	}

}

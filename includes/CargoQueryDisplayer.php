<?php

use MediaWiki\MediaWikiServices;

/**
 * CargoQueryDisplayer - class for displaying query results.
 *
 * @author Yaron Koren
 * @ingroup Cargo
 */

class CargoQueryDisplayer {

	public $mSQLQuery;
	public $mFormat;
	public $mDisplayParams = array();
	public $mParser = null;
	public $mFieldDescriptions;
	public $mFieldTables;

	public static function newFromSQLQuery( $sqlQuery ) {
		$cqd = new CargoQueryDisplayer();
		$cqd->mSQLQuery = $sqlQuery;
		$cqd->mFieldDescriptions = $sqlQuery->mFieldDescriptions;
		$cqd->mFieldTables = $sqlQuery->mFieldTables;
		return $cqd;
	}

	public static function getAllFormatClasses() {
		$formatClasses = array(
			'list' => 'CargoListFormat',
			'ul' => 'CargoULFormat',
			'ol' => 'CargoOLFormat',
			'template' => 'CargoTemplateFormat',
			'embedded' => 'CargoEmbeddedFormat',
			'csv' => 'CargoCSVFormat',
			'excel' => 'CargoExcelFormat',
			'json' => 'CargoJSONFormat',
			'outline' => 'CargoOutlineFormat',
			'tree' => 'CargoTreeFormat',
			'table' => 'CargoTableFormat',
			'dynamic table' => 'CargoDynamicTableFormat',
			'googlemaps' => 'CargoGoogleMapsFormat',
			'openlayers' => 'CargoOpenLayersFormat',
			'calendar' => 'CargoCalendarFormat',
			'timeline' => 'CargoTimelineFormat',
			'category' => 'CargoCategoryFormat',
			'bar chart' => 'CargoBarChartFormat',
			'gallery' => 'CargoGalleryFormat',
			'tag cloud' => 'CargoTagCloudFormat',
			'exhibit' => 'CargoExhibitFormat',
		);
		return $formatClasses;
	}

	/**
	 * Given a format name, and a list of the fields, returns the name
	 * of the function to call for that format.
	 */
	public function getFormatClass() {
		$formatClasses = self::getAllFormatClasses();
		if ( array_key_exists( $this->mFormat, $formatClasses ) ) {
			return $formatClasses[$this->mFormat];
		}

		$formatClass = null;
		Hooks::run( 'CargoGetFormatClass', array( $this->mFormat, &$formatClass ) );
		if ( $formatClass != null ) {
			return $formatClass;
		}

		if ( count( $this->mFieldDescriptions ) > 1 ) {
			$format = 'table';
		} else {
			$format = 'list';
		}
		return $formatClasses[$format];
	}

	public function getFormatter( $out, $parser = null ) {
		$formatClass = $this->getFormatClass();
		$formatObject = new $formatClass( $out, $parser );
		return $formatObject;
	}

	public function getFormattedQueryResults( $queryResults ) {
		// The assignment will do a copy.
		$formattedQueryResults = $queryResults;
		foreach ( $queryResults as $rowNum => $row ) {
			foreach ( $row as $fieldName => $value ) {
				if ( trim( $value ) == '' ) {
					continue;
				}

				if ( !array_key_exists( $fieldName, $this->mFieldDescriptions ) ) {
					continue;
				}

				$fieldDescription = $this->mFieldDescriptions[$fieldName];
				$tableName = $this->mFieldTables[$fieldName];
				$fieldType = $fieldDescription->mType;

				$text = '';
				if ( $fieldDescription->mIsList ) {
					// There's probably an easier way to do
					// this, using array_map().
					$delimiter = $fieldDescription->getDelimiter();
					// We need to decode it in case the delimiter is ;
					$value = html_entity_decode( $value );
					$fieldValues = explode( $delimiter, $value );
					foreach ( $fieldValues as $i => $fieldValue ) {
						if ( trim( $fieldValue ) == '' ) {
							continue;
						}
						if ( $i > 0 ) {
							// Use a bullet point as
							// the list delimiter -
							// it's better than using
							// a comma, or the
							// defined delimiter,
							// because it's more
							// consistent and makes
							// it clearer whether
							// list parsing worked.
							$text .= ' <span class="CargoDelimiter">&bull;</span> ';
						}
						$text .= self::formatFieldValue( $fieldValue, $fieldType, $fieldDescription, $this->mParser );
					}
				} elseif ( $fieldType == 'Date' || $fieldType == 'Datetime' ) {
					$datePrecisionField = str_replace( '_', ' ', $fieldName ) . '__precision';
					if ( array_key_exists( $datePrecisionField, $row ) ) {
						$datePrecision = $row[$datePrecisionField];
					} else {
						$fullDatePrecisionField = $tableName . '.' . $datePrecisionField;
						if ( array_key_exists( $fullDatePrecisionField, $row ) ) {
							$datePrecision = $row[$fullDatePrecisionField];
						} else {
							// This should never
							// happen, but if it
							// does - let's just
							// give up.
							$datePrecision = CargoStore::DATE_ONLY;
						}
					}
					$text = self::formatDateFieldValue( $value, $datePrecision, $fieldType );
				} elseif ( $fieldType == 'Boolean' ) {
					// Displaying a check mark for "yes"
					// and an x mark for "no" would be
					// cool, but those are apparently far
					// from universal symbols.
					$text = ( $value == true ) ? wfMessage( 'htmlform-yes' )->text() : wfMessage( 'htmlform-no' )->text();
				} elseif ( $fieldType == 'Searchtext' && $this->mSQLQuery && array_key_exists( $fieldName, $this->mSQLQuery->mSearchTerms ) ) {
					$searchTerms = $this->mSQLQuery->mSearchTerms[$fieldName];
					$text = Html::rawElement( 'span', array( 'class' => 'searchresult' ), self::getTextSnippet( $value, $searchTerms ) );
				} else {
					$text = self::formatFieldValue( $value, $fieldType, $fieldDescription, $this->mParser );
				}

				if ( array_key_exists( 'max display chars', $this->mDisplayParams ) && ( $fieldType == 'Text' || $fieldType == 'Wikitext' ) ) {
					$maxDisplayChars = $this->mDisplayParams['max display chars'];
					if ( strlen( $text ) > $maxDisplayChars && strlen( strip_tags( $text ) ) > $maxDisplayChars ) {
						$text = '<span class="cargoMinimizedText">' . $text . '</span>';
					}
				}

				if ( $text != '' ) {
					$formattedQueryResults[$rowNum][$fieldName] = $text;
				}
			}
		}
		return $formattedQueryResults;
	}

	public static function formatFieldValue( $value, $type, $fieldDescription, $parser ) {
		if ( $type == 'Integer' ) {
			global $wgCargoDecimalMark, $wgCargoDigitGroupingCharacter;
			return number_format( $value, 0, $wgCargoDecimalMark, $wgCargoDigitGroupingCharacter );
		} elseif ( $type == 'Float' ) {
			global $wgCargoDecimalMark, $wgCargoDigitGroupingCharacter;
			// Can we assume that the decimal mark will be a '.' in the database?
			$locOfDecimalPoint = strrpos( $value, '.' );
			if ( $locOfDecimalPoint === false ) {
				// Better to show "17.0" than "17", if it's a Float.
				$numDecimalPlaces = 1;
			} else {
				$numDecimalPlaces = strlen( $value ) - $locOfDecimalPoint - 1;
			}
			return number_format( $value, $numDecimalPlaces, $wgCargoDecimalMark,
				$wgCargoDigitGroupingCharacter );
		} elseif ( $type == 'Page' ) {
			$title = Title::newFromText( $value );
			if ( $title == null ) {
				return null;
			}
			if ( method_exists( 'MediaWiki\MediaWikiServices', 'getLinkRenderer' ) ) {
				$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
			} else {
				$linkRenderer = null;
			}
			// Hide the namespace in the display?
			global $wgCargoHideNamespaceName;
			if ( in_array( $title->getNamespace(), $wgCargoHideNamespaceName ) ) {
				return CargoUtils::makeLink( $linkRenderer, $title, $title->getRootText() );
			} else {
				return CargoUtils::makeLink( $linkRenderer, $title );
			}
		} elseif ( $type == 'File' ) {
			// 'File' values are basically pages in the File:
			// namespace; they are displayed as thumbnails within
			// queries.
			$title = Title::newFromText( $value, NS_FILE );
			if ( $title == null ) {
				return $value;
			}
			// makeThumbLinkObj() is still not deprecated in MW 1.28,
			// but presumably it will be at some point.
			return Linker::makeThumbLinkObj( $title, wfLocalFile( $title ), $value, '' );
		} elseif ( $type == 'URL' ) {
			if ( array_key_exists( 'link text', $fieldDescription->mOtherParams ) ) {
				return Html::element( 'a', array( 'href' => $value ),
						$fieldDescription->mOtherParams['link text'] );
			} else {
				// Otherwise, do nothing.
				return $value;
			}
		} elseif ( $type == 'Date' || $type == 'Datetime' ) {
			// This should not get called - date fields
			// have a separate formatting function.
			return $value;
		} elseif ( $type == 'Wikitext' || $type == '' ) {
			return CargoUtils::smartParse( $value, $parser );
		} elseif ( $type == 'Searchtext' ) {
			if ( strlen( $value ) > 300 ) {
				return substr( $value, 0, 300 ) . ' ...';
			} else {
				return $value;
			}
		}

		// If it's not any of these specially-handled types, just
		// return the value.
		return $value;
	}

	static function formatDateFieldValue( $dateValue, $datePrecision, $type ) {
		// Quick escape.
		if ( $dateValue == '' ) {
			return '';
		}

		$seconds = strtotime( $dateValue );
		if ( $datePrecision == CargoStore::YEAR_ONLY ) {
			// 'o' is better than 'Y' because it does not add
			// leading zeroes to years with fewer than four digits.
			// For some reason, this fails for some years -
			// returning one year lower than it's supposed to -
			// unless you add the equivalent of 3 days or more
			// to the number of seconds. Is that a leap day thing?
			// Weird PHP bug? Who knows. Anyway, it's easy to get
			// around.
			return date( 'o', $seconds + 300000 );
		} elseif ( $datePrecision == CargoStore::MONTH_ONLY ) {
			// Same issue as above.
			$seconds += 300000;
			return CargoDrilldownUtils::monthToString( date( 'm', $seconds ) ) .
				' ' . date( 'o', $seconds );
		} else {
			// CargoStore::DATE_AND_TIME or
			// CargoStore::DATE_ONLY
			global $wgAmericanDates;
			if ( $wgAmericanDates ) {
				// We use MediaWiki's representation of month
				// names, instead of PHP's, because its i18n
				// support is of course far superior.
				$dateText = CargoDrilldownUtils::monthToString( date( 'm', $seconds ) );
				$dateText .= ' ' . date( 'j, o', $seconds );
			} else {
				$dateText = date( 'o-m-d', $seconds );
			}
			// @TODO - remove the redundant 'Date' check at some
			// point. It's here because the "precision" constants
			// changed a ittle in version 0.8.
			if ( $type == 'Date' || $datePrecision == CargoStore::DATE_ONLY ) {
				return $dateText;
			}

			// It's a Datetime - add time as well.
			global $wgCargo24HourTime;
			if ( $wgCargo24HourTime ) {
				$timeText = date( 'G:i:s', $seconds );
			} else {
				$timeText = date( 'g:i:s A', $seconds );
			}
			return "$dateText $timeText";
		}
	}

	/**
	 * Based heavily on MediaWiki's SearchResult::getTextSnippet()
	 */
	function getTextSnippet( $text, $terms ) {
		global $wgAdvancedSearchHighlighting;
		list( $contextlines, $contextchars ) = SearchEngine::userHighlightPrefs();

		foreach ( $terms as $i => $term ) {
			// Try to map from a MySQL search to a PHP one -
			// this code could probably be improved.
			$term = str_replace( array( '"', "'", '+', '*' ), '', $term );
			// What is the point of this...?
			if ( strpos( $term, '*' ) !== false ) {
				$term = '\b' . $term . '\b';
			}
			$terms[$i] = $term;
		}

		// Replace newlines, etc. with spaces for better readability.
		$text = preg_replace( '/\s+/', ' ', $text );
		$h = new SearchHighlighter();
		if ( count( $terms ) > 0 ) {
			if ( $wgAdvancedSearchHighlighting ) {
				$snippet = $h->highlightText( $text, $terms, $contextlines, $contextchars );
			} else {
				$snippet = $h->highlightSimple( $text, $terms, $contextlines, $contextchars );
			}
		} else {
			$snippet = $h->highlightNone( $text, $contextlines, $contextchars );
		}

		// Why is this necessary for Cargo, but not for MediaWiki?
		return html_entity_decode( $snippet );
	}

	public function displayQueryResults( $formatter, $queryResults ) {
		if ( count( $queryResults ) == 0 ) {
			if ( array_key_exists( 'default', $this->mDisplayParams ) ) {
				return $this->mDisplayParams['default'];
			} else {
				return '<em>' . wfMessage( 'table_pager_empty' )->text() . '</em>'; // default
			}
		}

		$formattedQueryResults = $this->getFormattedQueryResults( $queryResults );
		$text = '';
		if ( array_key_exists( 'intro', $this->mDisplayParams ) ) {
			$text .= $this->mDisplayParams['intro'];
		}
		try {
			$text .= $formatter->display( $queryResults, $formattedQueryResults, $this->mFieldDescriptions,
				$this->mDisplayParams );
		} catch ( Exception $e ) {
			return '<div class="error">' . $e->getMessage() . '</div>';
		}
		if ( array_key_exists( 'outro', $this->mDisplayParams ) ) {
			$text .= $this->mDisplayParams['outro'];
		}
		return $text;
	}

	/**
	 * Display the link to view more results, pointing to Special:ViewData.
	 */
	public function viewMoreResultsLink( $displayHTML = true ) {
		$vd = Title::makeTitleSafe( NS_SPECIAL, 'ViewData' );
		if ( array_key_exists( 'more results text', $this->mDisplayParams ) ) {
			$moreResultsText = $this->mDisplayParams['more results text'];
			// If the value is blank, don't show a link at all.
			if ( $moreResultsText == '' ) {
				return '';
			}
		} else {
			$moreResultsText = wfMessage( 'moredotdotdot' )->parse();
		}

		$queryStringParams = array();
		$sqlQuery = $this->mSQLQuery;
		$queryStringParams['tables'] = $sqlQuery->mTablesStr;
		$queryStringParams['fields'] = $sqlQuery->mFieldsStr;
		if ( $sqlQuery->mOrigWhereStr != '' ) {
			$queryStringParams['where'] = $sqlQuery->mOrigWhereStr;
		}
		if ( $sqlQuery->mJoinOnStr != '' ) {
			$queryStringParams['join_on'] = $sqlQuery->mJoinOnStr;
		}
		if ( $sqlQuery->mGroupByStr != '' ) {
			$queryStringParams['group_by'] = $sqlQuery->mGroupByStr;
		}
		if ( $sqlQuery->mOrderByStr != '' ) {
			$queryStringParams['order_by'] = $sqlQuery->mOrderByStr;
		}
		if ( $this->mFormat != '' ) {
			$queryStringParams['format'] = $this->mFormat;
		}
		$queryStringParams['offset'] = $sqlQuery->mQueryLimit;
		$queryStringParams['limit'] = 100; // Is that a reasonable number in all cases?

		// Add format-specific params.
		foreach ( $this->mDisplayParams as $key => $value ) {
			$queryStringParams[$key] = $value;
		}

		if ( $displayHTML ) {
			if ( function_exists( 'MediaWiki\MediaWikiServices::getLinkRenderer' ) ) {
				$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
			} else {
				$linkRenderer = null;
			}
			return Html::rawElement( 'p', null,
				CargoUtils::makeLink( $linkRenderer, $vd, $moreResultsText, array(), $queryStringParams ) );
		} else {
			// Display link as wikitext.
			global $wgServer;
			return '[' . $wgServer . $vd->getLinkURL( $queryStringParams ) . ' ' . $moreResultsText . ']';
		}
	}

}

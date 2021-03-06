<?php
/**
 * Defines a class, CargoFilter, that holds the information in a filter.
 *
 * Based heavily on SD_Filter.php in the Semantic Drilldown extension.
 *
 * @author Yaron Koren
 * @ingroup Cargo
 */

class CargoFilter {
	public $name;
	public $tableName;
	public $fieldType;
	public $fieldDescription;
	public $searchableFiles;
	public $searchablePages;
	public $allowed_values;
	public $required_filters = array();
	public $possible_applied_filters = array();

	function __construct( $name, $tableName, $fieldDescription, $searchablePages, $searchableFiles ) {
		$this->name = $name;
		$this->tableName = $tableName;
		$this->fieldDescription = $fieldDescription;
		$this->searchablePages = $searchablePages;
		$this->searchableFiles = $searchableFiles;
	}

	public function addRequiredFilter( $filterName ) {
		$this->required_filters[] = $filterName;
	}

	public function getTableName() {
		return $this->tableName;
	}

	function getDateParts( $dateFromDB ) {
		// Does this only happen for MS SQL Server DBs?
		if ( $dateFromDB instanceof DateTime ) {
			$year = $dateFromDB->format( 'Y' );
			$month = $dateFromDB->format( 'm' );
			$day = $dateFromDB->format( 'd' );
			return array( $year, $month, $day );
		}

		// It's a string.
		$dateParts = explode( '-', $dateFromDB );
		if ( count( $dateParts ) == 3 ) {
			return $dateParts;
		} else {
			// year, month, day
		return array( $dateParts[0], 0, 0 );
		}
	}

	/**
	 *
	 * @param array $appliedFilters
	 * @return string
	 */
	function getTimePeriod( $fullTextSearchTerm, $appliedFilters ) {
		// If it's not a date field, return null.
		if ( !in_array( $this->fieldDescription->mType, array( 'Date', 'Datetime' ) ) ) {
			return null;
		}

		$cdb = CargoUtils::getDB();
		$date_field = $this->name;
		list( $tableNames, $conds, $joinConds ) = $this->getQueryParts( $fullTextSearchTerm, $appliedFilters );
		$res = $cdb->select( $tableNames, array( "MIN($date_field) AS min_date", "MAX($date_field) AS max_date" ), $conds, null,
			null, $joinConds );
		$row = $cdb->fetchRow( $res );
		$minDate = $row['min_date'];
		if ( is_null( $minDate ) ) {
			return null;
		}
		list( $minYear, $minMonth, $minDay ) = $this->getDateParts( $minDate );
		$maxDate = $row['max_date'];
		list( $maxYear, $maxMonth, $maxDay ) = $this->getDateParts( $maxDate );
		$yearDifference = $maxYear - $minYear;
		$monthDifference = ( 12 * $yearDifference ) + ( $maxMonth - $minMonth );
		if ( $yearDifference > 30 ) {
			return 'decade';
		} elseif ( $yearDifference > 2 ) {
			return 'year';
		} elseif ( $monthDifference > 1 ) {
			return 'month';
		} else {
			return 'day';
		}
	}

	/**
	 *
	 * @param array $appliedFilters
	 * @return array
	 */
	function getQueryParts( $fullTextSearchTerm, $appliedFilters ) {
		$cdb = CargoUtils::getDB();

		$tableNames = array( $this->tableName );
		$conds = array();
		$joinConds = array();

		if ( $fullTextSearchTerm != null ) {
			list( $curTableNames, $curConds, $curJoinConds ) =
				CargoDrilldownPage::getFullTextSearchQueryParts( $fullTextSearchTerm, $this->tableName, $this->searchablePages, $this->searchableFiles );
			$tableNames = array_merge( $tableNames, $curTableNames );
			$conds = array_merge( $conds, $curConds );
			$joinConds = array_merge( $joinConds, $curJoinConds );
		}

		foreach ( $appliedFilters as $af ) {
			$conds[] = $af->checkSQL();
			$fieldTableName = $this->tableName;
			$columnName = $af->filter->name;
			if ( $af->filter->fieldDescription->mIsList ) {
				$fieldTableName = $this->tableName . '__' . $af->filter->name;
				$tableNames[] = $fieldTableName;
				$joinConds[$fieldTableName] = CargoUtils::joinOfMainAndFieldTable( $cdb, $this->tableName, $fieldTableName );
				$columnName = '_value';
			}
			if ( $af->filter->fieldDescription->mIsHierarchy ) {
				$hierarchyTableName = $this->tableName . '__' . $af->filter->name . '__hierarchy';
				$tableNames[] = $hierarchyTableName;
				$joinConds[$hierarchyTableName] = CargoUtils::joinOfSingleFieldAndHierarchyTable( $cdb,
					$fieldTableName, $columnName, $hierarchyTableName );
			}
		}
		return array( $tableNames, $conds, $joinConds );
	}

	/**
	 * Gets an array of the possible time period values (e.g., years,
	 * months) for this filter, and, for each one,
	 * the number of pages that match that time period.
	 *
	 * @param array $appliedFilters
	 * @return array
	 */
	function getTimePeriodValues( $fullTextSearchTerm, $appliedFilters ) {
		$possible_dates = array();
		$date_field = $this->name;
		list( $yearValue, $monthValue, $dayValue ) = CargoUtils::getDateFunctions( $date_field );
		$timePeriod = $this->getTimePeriod( $fullTextSearchTerm, $appliedFilters );

		$fields = array();
		$fields['year_field'] = $yearValue;
		if ( $timePeriod == 'month' || $timePeriod == 'day' ) {
			$fields['month_field'] = $monthValue;
		}
		if ( $timePeriod == 'day' ) {
			$fields['day_of_month_field'] = $dayValue;
		}

		list( $tableNames, $conds, $joinConds ) = $this->getQueryParts( $fullTextSearchTerm, $appliedFilters );

		// Don't include imprecise date values in further filtering.
		if ( $timePeriod == 'month' ) {
			$conds[] = $date_field . '__precision <= ' . CargoStore::MONTH_ONLY;
		} elseif ( $timePeriod == 'day' ) {
			$conds[] = $date_field . '__precision <= ' . CargoStore::DATE_ONLY;
		}

		// We call array_values(), and not array_keys(), because
		// SQL Server can't group by aliases.
		$selectOptions = array( 'GROUP BY' => array_values( $fields ), 'ORDER BY' => array_values( $fields ) );
		if ( $this->searchableFiles ) {
			$fields['total'] = "COUNT(DISTINCT cargo__{$this->tableName}._pageID)";
		} else {
			$fields['total'] = "COUNT(*)";
		}

		$cdb = CargoUtils::getDB();

		$res = $cdb->select(
			$tableNames,
			$fields,
			$conds,
			__METHOD__,
			$selectOptions,
			$joinConds
		);

		while ( $row = $cdb->fetchRow( $res ) ) {
			$firstVal = current( $row ); // separate variable needed for PHP 5.3
			if ( empty( $firstVal ) ) {
				$possible_dates['_none'] = $row['total'];
			} elseif ( $timePeriod == 'day' ) {
				$date_string = CargoDrilldownUtils::monthToString( $row['month_field'] ) . ' ' . $row['day_of_month_field'] . ', ' . $row['year_field'];
				$possible_dates[$date_string] = $row['total'];
			} elseif ( $timePeriod == 'month' ) {
				$date_string = CargoDrilldownUtils::monthToString( $row['month_field'] ) . ' ' . $row['year_field'];
				$possible_dates[$date_string] = $row['total'];
			} elseif ( $timePeriod == 'year' ) {
				$date_string = $row['year_field'];
				$possible_dates[$date_string] = $row['total'];
			} else { // if ( $timePeriod == 'decade' )
				// Unfortunately, there's no SQL DECADE()
				// function - so we have to take these values,
				// which are grouped into year "buckets", and
				// re-group them into decade buckets.
				$year_string = $row['year_field'];
				$start_of_decade = $year_string - ( $year_string % 10 );
				$end_of_decade = $start_of_decade + 9;
				$decade_string = $start_of_decade . ' - ' . $end_of_decade;
				if ( !array_key_exists( $decade_string, $possible_dates ) ) {
					$possible_dates[$decade_string] = $row['total'];
				} else {
					$possible_dates[$decade_string] += $row['total'];
				}
			}
		}
		$cdb->freeResult( $res );
		return $possible_dates;
	}

	/**
	 * Gets an array of all values that this field has, and, for each
	 * one, the number of pages that match that value.
	 *
	 * @param array $appliedFilters
	 * @return array
	 */
	function getAllValues( $fullTextSearchTerm, $appliedFilters, $isApplied = false ) {
		$cdb = CargoUtils::getDB();

		list( $tableNames, $conds, $joinConds ) = $this->getQueryParts( $fullTextSearchTerm, $appliedFilters );
		if ( $this->fieldDescription->mIsList ) {
			$fieldTableName = $this->tableName . '__' . $this->name;
			if ( !$isApplied ) {
				$tableNames[] = $fieldTableName;
			}
			$fieldName = CargoUtils::escapedFieldName( $cdb, $fieldTableName, '_value' );
			$joinConds[$fieldTableName] = CargoUtils::joinOfMainAndFieldTable( $cdb, $this->tableName, $fieldTableName );
		} else {
			$fieldName = $cdb->addIdentifierQuotes( $this->name );
			if ( $isApplied ) {
				$tableNames = $this->tableName;
				$joinConds = null;
			}
		}
		if ( $isApplied ) {
			$conds = null;
		}
		if ( $this->searchableFiles ) {
			$countClause = "COUNT(DISTINCT cargo__{$this->tableName}._pageID) AS total";
		} else {
			$countClause = "COUNT(*) AS total";
		}
		$res = $cdb->select( $tableNames, array( "$fieldName AS value", $countClause ), $conds, null,
			array( 'GROUP BY' => $fieldName ), $joinConds );
		$possible_values = array();
		while ( $row = $cdb->fetchRow( $res ) ) {
			$value_string = $row['value'];
			if ( $value_string == '' ) {
				$value_string = ' none';
			}
			$possible_values[$value_string] = $row['total'];
		}
		$cdb->freeResult( $res );

		return $possible_values;
	}
}

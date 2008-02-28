<?php
#----------------------------------------------------------------------
#   Initialization and page contents.
#----------------------------------------------------------------------

require( '../_header.php' );
analyzeChoices();

showDescription();

if( $chosenShow ){
  showChoices();
  doTheDarnChecking();  
} else {
  echo "<p style='color:#F00;font-weight:bold'>I haven't done any checking yet, you must click 'Show' first (after optionally choosing event and/or competition).</p>";
  showChoices();
}

require( '../_footer.php' );

#----------------------------------------------------------------------
function showDescription () {
#----------------------------------------------------------------------

  echo "<p><b>This script *CAN* affect the database, namely if you tell it to.</b></p>";

  echo "<p style='color:#3C3;font-weight:bold'>New: You can now filter by competition. If you choose 'All' both for event and competition, I only show the differences (otherwise the page would be huge - btw it'll still take a long computation time).</p>";
  
  echo "<p>It computes regional record markers for all valid results (value>0). If a result has a stored or computed regional record marker, it is displayed. If the two markers differ, they're shown in red/green.</p>";

  echo "<p>Only strictly previous competitions (other.<b>end</b>Date &lt; this.<b>start</b>Date) are used to compare, not overlapping competitions. Thus I might wrongfully compute a too good record status (because a result was actually beaten earlier in an overlapping competition) but I should never wrongfully compute a too bad record status.</p>";

  echo "<p>Inside the same competition, results are sorted first by round, then by value, and then they're declared records on a first-come-first-served basis. This results in the records-are-updated-at-the-end-of-each-round rule you requested.</p>";

  echo "<p>A result does not need to beat another to get a certain record status, equaling is good enough.</p>";

  echo "<p>Please check it and let me know what you'd like me to do. I can modify the script to actually store the computed markers in the database, I can print SQL code to select the differing rows, I can print SQL code to update the differing rows...</p>";

  echo "<hr>";
}

#----------------------------------------------------------------------
function analyzeChoices () {
#----------------------------------------------------------------------
  global $chosenEventId, $chosenCompetitionId, $chosenShow, $chosenAnything;

  $chosenEventId        = getNormalParam( 'eventId' );
  $chosenCompetitionId  = getNormalParam( 'competitionId' );
  $chosenShow           = getBooleanParam( 'show' );
           
  $chosenAnything = $chosenEventId || $chosenCompetitionId;
}

#----------------------------------------------------------------------
function showChoices () {
#----------------------------------------------------------------------

  displayChoices( array(
    eventChoice( false ),
    competitionChoice( false ),
    choiceButton( true, 'show', 'Show' )
  ));
}

#----------------------------------------------------------------------
function doTheDarnChecking () {
#----------------------------------------------------------------------
  global $differencesWereFound;
    
    
  #--- Begin form and table.
  echo "<form action='check_regional_record_markers_ACTION.php' method='POST'>\n";
  tableBegin( 'results', 11 );

  #--- Do the checking.
  computeRegionalRecordMarkers( 'best', 'Single' );
  computeRegionalRecordMarkers( 'average', 'Average' );
  
  #--- End table.
  tableEnd();

  #--- Tell the result.
  $date = date( 'l dS \of F Y h:i:s A' );
  noticeBox2(
    ! $differencesWereFound,
    "We completely agree.<br />$date",
    "<p>Darn! We disagree!<br />$date</p><p>Choose the changes you agree with above, then click the 'Execute...' button below. It will result in something like the following. If you then go back in your browser and refresh the page, the changes should be visible.</p><pre>I'm doing this:
UPDATE Results SET regionalSingleRecord='WR' WHERE id=11111
UPDATE Results SET regionalSingleRecord='ER' WHERE id=22222
UPDATE Results SET regionalSingleRecord='NR' WHERE id=33333
</pre>"
  );

  #--- If differences were found, offer to fix them.
  if( $differencesWereFound )
    echo "<center><input type='submit' value='Execute the agreed $valueName changes!' /></center>\n";
  
  #--- Finish the form.
  echo "</form>\n";
}

#----------------------------------------------------------------------
function computeRegionalRecordMarkers ( $valueId, $valueName ) {
#----------------------------------------------------------------------
  global $chosenEventId;

  #--- Only one event or all (one by one)?
  if( $chosenEventId )
    computeRegionalRecordMarkersForChosenEvent( $valueId, $valueName );
  else {
    foreach( getAllEvents() as $event ){
      $chosenEventId = $event['id'];
      computeRegionalRecordMarkersForChosenEvent( $valueId, $valueName );
      ob_flush();
      flush();
    }
    $chosenEventId = '';
  }
}

#----------------------------------------------------------------------
function computeRegionalRecordMarkersForChosenEvent ( $valueId, $valueName ) {
#----------------------------------------------------------------------
  global $chosenAnything, $differencesWereFound;
  
  precomputeStuff( $valueId, $valueName );

  #--- Get all successful results.
  $results = dbQuery("
    SELECT
      year*10000 + month*100 + day startDate,
      result.*,
      $valueId value,
      continentId,
      continent.recordName continentalRecordName,
      event.format valueFormat
    FROM
      Results      result,
      Competitions competition,
      Countries    country,
      Continents   continent,
      Events       event
    WHERE 1
      AND $valueId > 0
      AND competition.id = result.competitionId
      AND country.id     = result.countryId
      AND continent.id   = country.continentId
      AND event.id       = result.eventId
      " . eventCondition() . competitionCondition() . "
    ORDER BY eventId, startDate, competitionId, roundId, $valueId
  ");

  #--- Process each result.
  foreach( $results as $result ){
    extract( $result );

    #--- Handle failures of multi-attempts.
    if( ! isSuccessValue( $value, $valueFormat ))
      continue;
    
    #--- Recognize new competitions.
    $isNewCompetition = ($competitionId != $currentCompetitionId);
    $currentCompetitionId = $competitionId;

    #--- If new competition, load records from strictly earlier competitions.
    if( $isNewCompetition )
      $record = getRecordsStrictlyBefore( $startDate );

    #--- Determine whether it's a new region record and update the records.
    $marker = '';
    if( $value <= $record[$eventId][$countryId] ){
      $marker = 'NR';
      $record[$eventId][$countryId] = $value;
    }
    if( $value <= $record[$eventId][$continentId] ){
      $marker = $continentalRecordName;
      $record[$eventId][$continentId] = $value;
    }
    if( $value <= $record[$eventId]['World'] ){
      $marker = 'WR';
      $record[$eventId]['World'] = $value;
    }

    #--- Get old (stored) and new (computed) marker.
    $oldMarker = ($valueId == 'best') ? $regionalSingleRecord : $regionalAverageRecord;
    $newMarker = $marker;

    #--- If old or new marker say it's some regional record at all...
    if( $oldMarker || $newMarker ){

      #--- Do old and new agree? Choose colors and update list of differences.
      $same = ($oldMarker == $newMarker);
      $oldColor = $same ? '999' : 'F00';
      $newColor = $same ? '999' : '0E0';
      if( ! $same ){
        $selectedIds[] = $id;
        $differencesWereFound = true;
      }

      #--- If no filter was chosen, only show differences.
      if( !$chosenAnything  &&  $same )
        continue;

      #--- Highlight regions if the new marker thinks it's a record for them.
      $countryName = $countryId;
      $continentName = substr( $continentId, 1 );
      $worldName = 'World';
      if( $newMarker )
        $countryName = "<b>$countryName</b>";
      if( $newMarker  &&  $newMarker != 'NR' )
        $continentName = "<b>$continentName</b>";
      if( $newMarker == 'WR' )
        $worldName = "<b>$worldName</b>";

      #--- Recognize new events/rounds/competitions.
      $isNewEvent = ($eventId != $currentEventId); $currentEventId = $eventId;
      $isNewRound = ($roundId != $currentRoundId); $currentRoundId = $roundId;
      $isNewCompo = ($competitionId != $currentCompoId); $currentCompoId = $competitionId;

      #--- If new event, announce it.
      if( $isNewEvent ){
        tableCaption( false, "$eventId $valueName" );
        tableHeader( split( '\\|', 'Competition|Round|Person|Event|Country|Continent|World|Value|Stored|Computed|Agree' ),
                     array( 7 => "class='R2'" ) );
      }
      
      #--- If new round/competition inside an event, add a separator row.
      if( ($isNewRound  ||  $isNewCompo)  &&  !$isNewEvent )
        tableRowEmpty();

      #--- Prepare the checkbox.
      $checkbox = "<input type='checkbox' name='update$valueName$id' value='$newMarker' />";
              
      #--- Show the result.
      tableRow( array(
#        $startDate,
        competitionLink( $competitionId, $competitionId ),
        $roundId,
        personLink( $personId, $personName ),
        $eventId,
        $countryName,
        $continentName,
        $worldName,
        formatValue( $value, $valueFormat ),
        "<span style='font-weight:bold;color:#$oldColor'>$oldMarker</span>",
        "<span style='font-weight:bold;color:#$newColor'>$newMarker</span>",
        ($same ? '' : $checkbox)
      ));
    }
  }
}

#----------------------------------------------------------------------
function precomputeStuff( $valueId, $valueName ) {
#----------------------------------------------------------------------
  global $recordsEndDate, $initialRecord;

  #--- Get all records for each competition end date.
  $recordsEndDate = dbQuery("
   SELECT
      year*10000 + if(endMonth,endMonth,month)*100 + if(endDay,endDay,day) endDate,
      eventId, result.countryId, continentId, min($valueId) value
    FROM
      Results result,
      Competitions competition,
      Countries country
    WHERE 1
      AND $valueId > 0
      AND competition.id = competitionId
      AND country.id = result.countryId
      " . eventCondition() . regionCondition( 'result' ) . "
    GROUP BY endDate, eventId, countryId
    ORDER BY eventId, endDate, countryId, $valueId
  ");

  #--- Create an initial record list that can be reused later.
  $tmp = dbQuery("
    SELECT DISTINCT eventId, countryId, continentId
    FROM Results, Countries country
    WHERE country.id = countryId
  ");
  foreach( $tmp as $row ){
    extract( $row );
    foreach( array( $countryId, $continentId, 'World' ) as $regionId )
      $initialRecord[$eventId][$regionId] = 2000000000;
  }
}

#----------------------------------------------------------------------
function getRecordsStrictlyBefore( $startDate ) {
#----------------------------------------------------------------------
  global $recordsEndDate, $initialRecord;

  $record = $initialRecord;
  foreach( $recordsEndDate as $row ){
    extract( $row );
    if( $endDate < $startDate ){
      foreach( array( $countryId, $continentId, 'World' ) as $regionId )
        if( $value < $record[$eventId][$regionId] )
          $record[$eventId][$regionId] = $value;
    }
  }
  
  return $record;
}

?>

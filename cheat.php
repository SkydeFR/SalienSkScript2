#!/usr/bin/env php
<?php

Msg( "{background-blue}Welcome to SalienSkScript" );

set_time_limit( 0 );
error_reporting( -1 );
ini_set( 'display_errors', '1' );

if( !function_exists( 'random_int' ) )
{
	function random_int( $min, $max )
	{
		return mt_rand( $min, $max );
	}
}

if( !file_exists( __DIR__ . '/cacert.pem' ) )
{
	Msg( 'You forgot to download cacert.pem file' );
	exit( 1 );
}

// Pass env ACCOUNTID, get it from salien page source code called 'gAccountID'
$AccountID = isset( $_SERVER[ 'ACCOUNTID' ] ) ? (int)$_SERVER[ 'ACCOUNTID' ] : 0;

if( $argc > 1 )
{
	$Token = $argv[ 1 ];
	
	if( $argc > 2 )
	{
		$AccountID = GetAccountID( $argv[ 2 ] );
		$Persona_Name = GetAccountName( $argv[ 2 ] );
		Msg( 'Your SteamID is {teal}' . $argv[ 2 ] . '{normal} - AccountID is {teal}' . $AccountID );
		
		if( $AccountID == 0 && $argv[ 2 ] > 0 )
		{
			Msg( '{lightred}Looks like you are using 32bit PHP. Try enabling "gmp" module for correct accountid calculation.' );
		}
	}
}
else if( isset( $_SERVER[ 'TOKEN' ] ) )
{
	// If the token was provided as an env var, use it
	$Token = $_SERVER[ 'TOKEN' ];
}
else
{
	// Otherwise, read it from disk
	$Token = trim( file_get_contents( __DIR__ . '/token.txt' ) );
	$ParsedToken = json_decode( $Token, true );
	
	if( is_string( $ParsedToken ) )
	{
		$Token = $ParsedToken;
	}
	else if( isset( $ParsedToken[ 'token' ] ) )
	{
		$Token = $ParsedToken[ 'token' ];
		$AccountID = GetAccountID( $ParsedToken[ 'steamid' ] );
		$Persona_Name = $ParsedToken[ 'persona_name' ];
		
		// Strip names down to basic ASCII.
		$RegMask = '/[\x00-\x1F\x7F-\xFF]/';
		$Persona_Name = trim( preg_replace( $RegMask, '', $Persona_Name ) );

		Msg( 'Your SteamID is {teal}' . $ParsedToken[ 'steamid' ] . '{normal} - AccountID is {teal}' . $AccountID );
		
		if( $AccountID == 0 && $ParsedToken[ 'steamid' ] > 0 )
		{
			Msg( '{lightred}Looks like you are using 32bit PHP. Try enabling "gmp" module for correct accountid calculation.' );
		}
	}
	
	unset( $ParsedToken );
}

if( strlen( $Token ) !== 32 )
{
	Msg( 'Failed to find your token. Verify token.txt' );
	exit( 1 );
}

$LocalScriptHash = sha1( trim( file_get_contents( __FILE__ ) ) );
if( isset($Persona_Name) && $Persona_Name !== 0 )
{			
	Msg('Account Name : {teal}' . $Persona_Name . '{normal} - File hash is {teal}' . substr( $LocalScriptHash, 0, 8 ) );
}
else
{
	Msg('Account Name : {teal}' . 'Unknown' . '{normal} - File hash is {teal}' . substr( $LocalScriptHash, 0, 8 ) );
}

if( isset( $_SERVER[ 'IGNORE_UPDATES' ] ) && (bool)$_SERVER[ 'IGNORE_UPDATES' ] )
{
	$UpdateCheck = false;
}
else
{
	$UpdateCheck = true;
	$RepositoryScriptETag = '';
	$RepositoryScriptHash = GetRepositoryScriptHash( $RepositoryScriptETag, $LocalScriptHash );
}

$DisableColors = !(
	( function_exists( 'sapi_windows_vt100_support' ) && sapi_windows_vt100_support( STDOUT ) ) ||
	( function_exists( 'stream_isatty' ) && stream_isatty( STDOUT ) ) ||
	( function_exists( 'posix_isatty' ) && posix_isatty( STDOUT ) ) ||
	( false !== getenv( 'ANSICON' ) ) ||
	( 'ON' === getenv( 'ConEmuANSI' ) ) ||
	( substr( getenv( 'TERM' ), 0, 5 ) === 'xterm' )
);

if( isset( $_SERVER[ 'DISABLE_COLORS' ] ) )
{
	$DisableColors = (bool)$_SERVER[ 'DISABLE_COLORS' ];
}

$GameVersion = 2;
$WaitTime = 110;
$FailSleep = 3;
$OldScore = 0;
$LastKnownPlanet = 0;
$BestPlanetAndZone = 0;
$ShowMinorErrors = 0;

if( ini_get( 'precision' ) < 18 )
{
	ini_set( 'precision', '18' );
}

do
{
	$Data = SendPOST( 'ITerritoryControlMinigameService/GetPlayerInfo', 'access_token=' . $Token, $ShowMinorErrors );
	
	if( isset( $Data[ 'response' ][ 'score' ] ) )
	{
		$OldScore = $Data[ 'response' ][ 'score' ];
		
		Msg( '{green}-- If you want to support a steam group and represent it in game:' );
		Msg( '{green}-- Just join the group and set us as your clan on' );
		Msg( '{green}--{yellow} https://steamcommunity.com/saliengame/play' );
		Msg( '{lightred}-- SalienSkScript starts!' );
		echo PHP_EOL;
		
		Msg(
			'++ Your Score: {teal}' . number_format( $Data[ 'response' ][ 'score' ] ) .
			'{normal} - Current Level: {teal}' . $Data[ 'response' ][ 'level' ]
		);
		echo PHP_EOL;
	}
	
}
while( !isset( $Data[ 'response' ][ 'score' ] ) && sleep( $FailSleep ) === 0 );

do
{
	if( !$BestPlanetAndZone )
	{
		do
		{
			$BestPlanetAndZone = GetBestPlanetAndZone( $ShowMinorErrors, $WaitTime, $FailSleep );
		}
		while( !$BestPlanetAndZone && sleep( $FailSleep ) === 0 );
	}
	
	echo PHP_EOL;
	
	// Only get player info and leave current planet if it changed
	if( $LastKnownPlanet !== $BestPlanetAndZone[ 'id' ] )
	{
		do
		{
			// Leave current game before trying to switch planets (it will report InvalidState otherwise)
			$SteamThinksPlanet = LeaveCurrentGame( $ShowMinorErrors, $Token, $FailSleep, $BestPlanetAndZone[ 'id' ] );
			
			if( $BestPlanetAndZone[ 'id' ] !== $SteamThinksPlanet )
			{
				SendPOST( 'ITerritoryControlMinigameService/JoinPlanet', 'id=' . $BestPlanetAndZone[ 'id' ] . '&access_token=' . $Token, $ShowMinorErrors );
				
				$SteamThinksPlanet = LeaveCurrentGame( $ShowMinorErrors, $Token, $FailSleep );
			}
		}
		while( $BestPlanetAndZone[ 'id' ] !== $SteamThinksPlanet && sleep( $FailSleep ) === 0 );
		
		$LastKnownPlanet = $BestPlanetAndZone[ 'id' ];
	}
	
	if( $BestPlanetAndZone[ 'best_zone' ][ 'boss_active' ] )
	{
		$Zone = SendPOST( 'ITerritoryControlMinigameService/JoinBossZone', 'zone_position=' . $BestPlanetAndZone[ 'best_zone' ][ 'zone_position' ] . '&access_token=' . $Token, $ShowMinorErrors );
		
		if( $Zone[ 'eresult' ] != 1 )
		{
			if( $ShowMinorErrors )
			{
				Msg( '{lightred}!! Failed to join boss zone, rescanning and restarting...' );
			}
			else
			{
				Msg( '{lightred}-- Zone is overloaded, rescanning...' );
			}
			
			$BestPlanetAndZone = 0;
			
			sleep( $FailSleep );
			
			continue;
		}
		
		// Avoid first time not sync error
		sleep( 4 );
		
		$BossFailsAllowed = 10;
		$NextHeal = PHP_INT_MAX;
		$WaitingForPlayers = true;
		$MyScoreInBoss = 0;
		$BossEstimate =
		[
			'InitHP' => 0,
			'PrevHP' => 0,
			'PrevXP' => 0,
			'DeltHP' => [],
			'DeltXP' => []
		];
		
		do
		{
			$Time = microtime( true );
			$UseHeal = 0;
			$DamageToBoss = $WaitingForPlayers ? 0 : 1;
			$DamageTaken = 0;
			
			if( $Time >= $NextHeal )
			{
				$UseHeal = 1;
				$NextHeal = $Time + 120;
			}
			
			$Data = SendPOST( 'ITerritoryControlMinigameService/ReportBossDamage', 'access_token=' . $Token . '&use_heal_ability=' . $UseHeal . '&damage_to_boss=' . $DamageToBoss . '&damage_taken=' . $DamageTaken, $ShowMinorErrors );
			
			if( $Data[ 'eresult' ] == 11 )
			{
				Msg( '{green}@@ Got invalid state, restarting...' );
				
				break;
			}
			
			if( $Data[ 'eresult' ] != 1 && $BossFailsAllowed-- < 1 )
			{
				Msg( '{green}@@ Boss battle errored too much, restarting...' );
				
				break;
			}
			
			if( empty( $Data[ 'response' ][ 'boss_status' ] ) )
			{
				Msg( '{green}@@ Waiting...' );
				continue;
			}
			
			if( $Data[ 'response' ][ 'waiting_for_players' ] )
			{
				$WaitingForPlayers = true;
				Msg( '{green}@@ Waiting for players...' );
				continue;
			}
			else if( $WaitingForPlayers )
			{
				$WaitingForPlayers = false;
				$NextHeal = $Time + random_int( 0, 120 );
			}
			
			usort( $Data[ 'response' ][ 'boss_status' ][ 'boss_players' ], function( $a, $b ) use( $AccountID )
			{
				if( $a[ 'accountid' ] == $AccountID )
				{
					return 1;
				}
				else if( $b[ 'accountid' ] == $AccountID )
				{
					return -1;
				}
				
				return $b[ 'accountid' ] - $a[ 'accountid' ];
			} );
			
			$LastXP_Earned = 0;
			
			if( $Data[ 'response' ][ 'game_over' ] )
			{
				Msg( '{green}@@ Boss battle is over !' );
				$LastXP_Earned = $MyPlayer[ 'xp_earned' ];
			}
			
			$MyPlayer = null;
			
			foreach( $Data[ 'response' ][ 'boss_status' ][ 'boss_players' ] as $Player )
			{
				$IsThisMe = $Player[ 'accountid' ] == $AccountID;
				$DefaultColor = $IsThisMe ? '{green}' : '{normal}';
				
				if( $IsThisMe )
				{
					$MyPlayer = $Player;
				}
			
				// Strip names down to basic ASCII.
				$RegMask = '/[\x00-\x1F\x7F-\xFF]/';
				
				$Name = trim( preg_replace( $RegMask, '', $Player[ 'name' ] ) );
				
				Msg(
					( $IsThisMe ? '{green}@@' : '  ' ) .
					' %-20s - HP: {yellow}%6s' . $DefaultColor  . ' / %6s - XP Gained: {yellow}%10s' . $DefaultColor,
					PHP_EOL,
					[
						empty( $Name ) ? ( '[U:1:' . $Player[ 'accountid' ] . ']' ) : substr( $Name, 0, 20 ),
						$Player[ 'hp' ],
						$Player[ 'max_hp' ],
						number_format( $Player[ 'xp_earned' ] )
					]
				);
			}
			
			if( $MyPlayer !== null )
			{
				$MyScoreInBoss = $MyPlayer[ 'score_on_join' ] + $MyPlayer[ 'xp_earned' ];
				
				Msg( '@@ Started XP: ' . number_format( $MyPlayer[ 'score_on_join' ] ) . ' {teal}(L' . $MyPlayer[ 'level_on_join' ] . '){normal} - Current XP: {yellow}' . number_format( $MyScoreInBoss ) . ' ' . ( $MyPlayer[ 'level_on_join' ] != $MyPlayer[ 'new_level' ] ? '{green}' : '{teal}' ) . '(L' . $MyPlayer[ 'new_level' ] . ')' );
				
				if( $MyPlayer[ 'hp' ] <= 0 )
				{
					Msg( '{lightred}-- You died in the Boss battle, restarting...' );
					break;
				}
			}
			
			if( $Data[ 'response' ][ 'game_over' ] )
			{
				if( $MyScoreInBoss > 0 )
				{
					if( $LastXP_Earned > 0 )
					{
						Msg('{green}++ XP bonus: {yellow}(+' . number_format( $MyScoreInBoss - $OldScore - $LastXP_Earned ) . ')');
					}
					Msg('++ Your XP after Boss battle: {lightred}' . number_format( $MyScoreInBoss ) . ' {yellow}(+' . number_format( $MyScoreInBoss - $OldScore ) . ')');
					$OldScore = $MyScoreInBoss;
				}
				
				echo PHP_EOL;
				
				break;
			}
			
			// Boss XP, DPS and Time Estimation
			if( $BossEstimate[ 'PrevXP' ] > 0 )
			{
				// Calculate HP and XP change per game tick
				$BossEstimate[ 'DeltHP' ][] = abs( $BossEstimate[ 'PrevHP' ] - $Data[ 'response' ][ 'boss_status' ][ 'boss_hp' ] );
				$BossEstimate[ 'DeltXP' ][] = ( $MyPlayer !== null ? abs( $BossEstimate[ 'PrevXP' ] - $MyPlayer[ 'xp_earned' ] ) : 1 );
				
				// Calculate XP rate, Boss damage per game tick (2500xp/tick fallback for players without $AccountID) and game ticks Remaining
				$EstXPRate = ( $MyPlayer !== null ? array_sum( $BossEstimate[ 'DeltXP' ] ) / count( $BossEstimate[ 'DeltXP' ] ) : 2500 );
				$EstBossDPT = array_sum( $BossEstimate[ 'DeltHP' ] ) / count( $BossEstimate[ 'DeltHP' ] );
				$EstTickRemain = $Data[ 'response' ][ 'boss_status' ][ 'boss_hp' ] / $EstBossDPT;
				
				// Calculate Total XP Reward for Boss
				$EstXPTotal = ( $MyPlayer !== null ? $MyPlayer[ 'xp_earned' ] + ( $EstTickRemain * $EstXPRate ) : ( $BossEstimate[ 'InitHP' ] / $EstBossDPT ) * $EstXPRate );
				
				// Display Estimated XP and DPS
				Msg( '@@ Estimated Final XP: {lightred}' . number_format( $EstXPTotal ) . "{normal} ({yellow}+" . number_format( $EstXPRate ) . "{normal}/tick excl. bonuses) - Damage per Second: {green}" . number_format( $EstBossDPT / 5 ) );
				
				// Display Estimated Time Remaining
				Msg( '@@ Estimated Time Remaining: {teal}' . gmdate( 'H:i:s', $EstTickRemain * 5 ) );
				
				// Only keep the last 1 minute of game time (12 ticks) in BossEstimate
				if( count( $BossEstimate[ 'DeltHP' ] ) >= 12 )
				{
					array_shift( $BossEstimate[ 'DeltHP' ] );
					array_shift( $BossEstimate[ 'DeltXP' ] );
				}
			}
			
			// Set Initial HP Once, Log HP and XP every tick
			$BossEstimate[ 'InitHP' ] = ( $BossEstimate[ 'InitHP' ] ?: $Data[ 'response' ][ 'boss_status' ][ 'boss_hp' ] );
			$BossEstimate[ 'PrevHP' ] = $Data[ 'response' ][ 'boss_status' ][ 'boss_hp' ];
			$BossEstimate[ 'PrevXP' ] = ( $MyPlayer !== null ? $MyPlayer[ 'xp_earned' ] : 1 );
			
			Msg( '@@ Boss HP: {green}' . number_format( $Data[ 'response' ][ 'boss_status' ][ 'boss_hp' ] ) . '{normal} / {lightred}' .  number_format( $Data[ 'response' ][ 'boss_status' ][ 'boss_max_hp' ] ) . '{normal} - Lasers: {yellow}' . $Data[ 'response' ][ 'num_laser_uses' ] . '{normal} - Team Heals: {green}' . $Data[ 'response' ][ 'num_team_heals' ] );
			Msg( '{normal}@@ Damage sent: {green}' . $DamageToBoss . '{normal} - ' . ( $UseHeal ? '{green}Used heal ability!' : 'Next heal in {green}' . round( $NextHeal - $Time ) . '{normal} seconds' ) );
			
			echo PHP_EOL;
		}
		while( BossSleep( $c ) );
		
		// After a boss battle, reset state and scan again
		$BestPlanetAndZone = 0;
		$LastKnownPlanet = 0;
		
		unset( $BossEstimate );
		
		continue;
	}
	
	$Zone = SendPOST( 'ITerritoryControlMinigameService/JoinZone', 'zone_position=' . $BestPlanetAndZone[ 'best_zone' ][ 'zone_position' ] . '&access_token=' . $Token, $ShowMinorErrors );
	$PlanetCheckTime = microtime( true );
	
	// Rescan planets if joining failed
	if( empty( $Zone[ 'response' ][ 'zone_info' ] ) )
	{
		if( $ShowMinorErrors )
		{
			Msg( '{lightred}!! Failed to join a zone, rescanning and restarting...' );
		}
		else
		{
			Msg( '{lightred}-- Zone is overloaded, rescanning...' );
		}
		
		$BestPlanetAndZone = 0;
		
		sleep( $FailSleep );
		
		continue;
	}
	
	$Zone = $Zone[ 'response' ][ 'zone_info' ];
	
	Msg(
		'++ Joined Zone {yellow}' . $Zone[ 'zone_position' ] .
		'{normal} on Planet {green}' . $BestPlanetAndZone[ 'id' ] .
		'{normal} - Captured: {yellow}' . number_format( empty( $Zone[ 'capture_progress' ] ) ? 0.0 : ( $Zone[ 'capture_progress' ] * 100 ), 2 ) . '%' .
		'{normal} - Difficulty: {yellow}' . GetNameForDifficulty( $Zone )
	);
	
	$SkippedLagTime = curl_getinfo( $c, CURLINFO_TOTAL_TIME ) - curl_getinfo( $c, CURLINFO_STARTTRANSFER_TIME );
	$SkippedLagTime -= fmod( $SkippedLagTime, 0.1 );
	$LagAdjustedWaitTime = $WaitTime - $SkippedLagTime;
	$WaitTimeBeforeFirstScan = $WaitTime - $SkippedLagTime - 10;
	
	if( $UpdateCheck )
	{
		if( $LocalScriptHash === $RepositoryScriptHash )
		{
			$RepositoryScriptHash = GetRepositoryScriptHash( $RepositoryScriptETag, $LocalScriptHash );
		}

		if( $LocalScriptHash !== $RepositoryScriptHash )
		{
			Msg( '-- {lightred}Script has been updated on GitHub since you started this script, please make sure to update.' );
		}
	}
	
	Msg( '== {teal}Waiting ' . number_format( $WaitTimeBeforeFirstScan, 3 ) . ' (+' . number_format( $SkippedLagTime, 3 ) . ' second lag) seconds before rescanning planets...' );
	
	usleep( $WaitTimeBeforeFirstScan * 1000000 );
	
	do
	{
		$BestPlanetAndZone = GetBestPlanetAndZone( $ShowMinorErrors, $WaitTime, $FailSleep );
	}
	while( !$BestPlanetAndZone && sleep( $FailSleep ) === 0 );
	
	if( $BestPlanetAndZone[ 'best_zone' ][ 'boss_active' ] )
	{
		Msg( '{green}@@ Boss detected, abandoning current zone and joining boss...' );
		
		$LastKnownPlanet = 0;
		
		continue;
	}
	
	$LagAdjustedWaitTime -= microtime( true ) - $PlanetCheckTime;
	
	if( $LagAdjustedWaitTime > 0 )
	{
		Msg( '== {teal}Waiting ' . number_format( $LagAdjustedWaitTime, 3 ) . ' remaining seconds before submitting score...' );
		
		usleep( $LagAdjustedWaitTime * 1000000 );
	}
	
	$Data = SendPOST( 'ITerritoryControlMinigameService/ReportScore', 'access_token=' . $Token . '&score=' . GetScoreForZone( $Zone ) . '&language=english', $ShowMinorErrors );
	
	if( empty( $Data[ 'response' ][ 'new_score' ] ) )
	{
		$LagAdjustedWaitTime = max( 1, min( 10, round( $SkippedLagTime ) ) );
		
		if( $ShowMinorErrors )
		{
			Msg( '{lightred}-- Report score failed, trying again in ' . $LagAdjustedWaitTime . ' seconds...' );
		}
		
		sleep( $LagAdjustedWaitTime );
		
		$Data = SendPOST( 'ITerritoryControlMinigameService/ReportScore', 'access_token=' . $Token . '&score=' . GetScoreForZone( $Zone ) . '&language=english', $ShowMinorErrors );
	}
	
	if( isset( $Data[ 'response' ][ 'new_score' ] ) )
	{
		$Data = $Data[ 'response' ];
		
		echo PHP_EOL;
		
		// Store our own old score because the API may increment score while giving an error (e.g. a timeout)
		if( !$OldScore )
		{
			$OldScore = $Data[ 'old_score' ];
		}
		
		Msg(
			'++ Your Score: {lightred}' . number_format( $Data[ 'new_score' ] ) .
			'{yellow} (+' . number_format( $Data[ 'new_score' ] - $OldScore ) . ')' .
			'{normal} - Current Level: {green}' . $Data[ 'new_level' ] .
			'{normal} (' . number_format( GetNextLevelProgress( $Data ) * 100, 2 ) . '%)'
		);
		
		$OldScore = $Data[ 'new_score' ];
		
		if( isset( $Data[ 'next_level_score' ] ) )
		{
			$WaitTimeSeconds = $WaitTime / 60;
			$Time = ( ( $Data[ 'next_level_score' ] - $Data[ 'new_score' ] ) / GetScoreForZone( [ 'difficulty' => $Zone[ 'difficulty' ] ] ) * $WaitTimeSeconds ) + $WaitTimeSeconds;
			$Hours = floor( $Time / 60 );
			$Minutes = $Time % 60;
			$Date = date_create();
			
			date_add( $Date, date_interval_create_from_date_string( $Hours . " hours + " . $Minutes . " minutes" ) );
			
			Msg(
				'>> Next Level: {yellow}' . number_format( $Data[ 'next_level_score' ] ) .
				'{normal} - Remaining: {yellow}' . number_format( $Data[ 'next_level_score' ] - $Data[ 'new_score' ] ) .
				'{normal} - ETA: {green}' . $Hours . 'h ' . $Minutes . 'm (' . date_format( $Date , "jS H:i T" ) . ')'
			);
		}
	}
}
while( true );

function BossSleep( $c )
{
	$SkippedLagTime = curl_getinfo( $c, CURLINFO_TOTAL_TIME ) - curl_getinfo( $c, CURLINFO_STARTTRANSFER_TIME );
	$SkippedLagTime -= fmod( $SkippedLagTime, 0.1 );
	$LagAdjustedWaitTime = 5 - $SkippedLagTime;
	
	if( $LagAdjustedWaitTime > 0 )
	{
		usleep( $LagAdjustedWaitTime * 1000000 );
	}
	
	return true;
}

function CheckGameVersion( $Data )
{
	global $GameVersion;
	
	if( !isset( $Data[ 'response' ][ 'game_version' ] ) || $GameVersion >= $Data[ 'response' ][ 'game_version' ] )
	{
		return;
	}

	Msg( '{lightred}!! Game version changed to ' . $Data[ 'response' ][ 'game_version' ] );
}

function GetNextLevelProgress( $Data )
{
	if( !isset( $Data[ 'next_level_score' ] ) )
	{
		return 1;
	}
	
	$ScoreTable =
	[
		0,       // Level 1
		1200,    // Level 2
		2400,    // Level 3
		4800,    // Level 4
		12000,   // Level 5
		30000,   // Level 6
		72000,   // Level 7
		180000,  // Level 8
		450000,  // Level 9
		1200000, // Level 10
		2400000, // Level 11
		3600000, // Level 12
		4800000, // Level 13
		6000000, // Level 14
		7200000, // Level 15
		8400000, // Level 16
		9600000, // Level 17
		10800000, // Level 18
		12000000, // Level 19
		14400000, // Level 20
		16800000, // Level 21
		19200000, // Level 22
		21600000, // Level 23
		24000000, // Level 24
		26400000, // Level 25
	];
	
	$PreviousLevel = $Data[ 'new_level' ] - 1;
	
	if( !isset( $ScoreTable[ $PreviousLevel ] ) )
	{
		Msg( '{lightred}!! Score for next level is unknown !!' );
		return 0;
	}
	
	return ( $Data[ 'new_score' ] - $ScoreTable[ $PreviousLevel ] ) / ( $Data[ 'next_level_score' ] - $ScoreTable[ $PreviousLevel ] );
}

function GetScoreForZone( $Zone )
{
	switch( $Zone[ 'difficulty' ] )
	{
		case 1: $Score = 5; break;
		case 2: $Score = 10; break;
		case 3: $Score = 20; break;
		
		// Set fallback score equal to high zone score to avoid uninitialized
		// variable if new zone difficulty is introduced (e.g., for boss zones)
		default: $Score = 20;
	}
	
	return $Score * 120;
}

function GetNameForDifficulty( $Zone )
{
	$Boss = $Zone[ 'type' ] == 4 ? 'BOSS - ' : '';
	$Difficulty = $Zone[ 'difficulty' ];
	
	switch( $Zone[ 'difficulty' ] )
	{
		case 3: $Difficulty = 'High'; break;
		case 2: $Difficulty = 'Medium'; break;
		case 1: $Difficulty = 'Low'; break;
	}
	
	return $Boss . $Difficulty;
}

function GetPlanetState( $ShowMinorErrors, $Planet, $WaitTime )
{
	$Zones = SendGET( 'ITerritoryControlMinigameService/GetPlanet', 'id=' . $Planet . '&language=english', $ShowMinorErrors );
	
	if( empty( $Zones[ 'response' ][ 'planets' ][ 0 ][ 'zones' ] ) )
	{
		return null;
	}
	
	$Zones = $Zones[ 'response' ][ 'planets' ][ 0 ][ 'zones' ];
	$CleanZones = [];
	$HighZones = 0;
	$MediumZones = 0;
	$LowZones = 0;
	$BossZones = [];
	
	foreach( $Zones as &$Zone )
	{
		if( empty( $Zone[ 'capture_progress' ] ) )
		{
			$Zone[ 'capture_progress' ] = 0.0;
		}
		
		if( !isset( $Zone[ 'boss_active' ] ) )
		{
			$Zone[ 'boss_active' ] = false;
		}
		
		if( $Zone[ 'captured' ] )
		{
			continue;
		}
		
		// Store boss zone separately to ensure it has priority later
		if( $Zone[ 'type' ] == 4 && $Zone[ 'boss_active' ] )
		{
			$BossZones[] = $Zone;
		}
		
		// If a zone is close to completion, skip it because we want to avoid joining a completed zone
		// Valve now rewards points, if the zone is completed before submission
		if( $Zone[ 'capture_progress' ] >= 0.98 )
		{
			continue;
		}
		
		switch( $Zone[ 'difficulty' ] )
		{
			case 3: $HighZones++; break;
			case 2: $MediumZones++; break;
			case 1: $LowZones++; break;
		}
		
		$CleanZones[] = $Zone;
	}
	
	unset( $Zone );
	
	if( !empty( $BossZones ) )
	{
		$CleanZones = $BossZones;
	}
	else if( count( $CleanZones ) < 2 )
	{
		return false;
	}
	
	shuffle($CleanZones);
	
	return [
		'high_zones' => $HighZones,
		'medium_zones' => $MediumZones,
		'low_zones' => $LowZones,
		'best_zone' => $CleanZones[ 0 ],
	];
}

function GetBestPlanetAndZone( $ShowMinorErrors, $WaitTime, $FailSleep )
{
	$Planets = SendGET( 'ITerritoryControlMinigameService/GetPlanets', 'active_only=1&language=english', $ShowMinorErrors );
	
	CheckGameVersion( $Planets );
	
	if( empty( $Planets[ 'response' ][ 'planets' ] ) )
	{
		if( isset( $Planets[ 'response' ][ 'game_version' ] ) )
		{
			Msg( '{background-blue} There are no active planets left! Shutdown SalienSkScript...' );
			exit( 0 );
		}
		return null;
	}
	
	$Planets = $Planets[ 'response' ][ 'planets' ];
	
	usort( $Planets, function( $a, $b )
	{
		$a = isset( $a[ 'state' ][ 'boss_zone_position' ] ) ? 1000 : $a[ 'id' ];
		$b = isset( $b[ 'state' ][ 'boss_zone_position' ] ) ? 1000 : $b[ 'id' ];
		
		return $b - $a;
	} );
	
	foreach( $Planets as &$Planet )
	{
		$Planet[ 'sort_key' ] = 0;
		
		if( empty( $Planet[ 'state' ][ 'capture_progress' ] ) )
		{
			$Planet[ 'state' ][ 'capture_progress' ] = 0.0;
		}
		
		if( empty( $Planet[ 'state' ][ 'current_players' ] ) )
		{
			$Planet[ 'state' ][ 'current_players' ] = 0;
		}
		
		do
		{
			$Zone = GetPlanetState( $ShowMinorErrors, $Planet[ 'id' ], $WaitTime );
		}
		while( $Zone === null && sleep( $FailSleep ) === 0 );
		
		if( $Zone === false )
		{
			$Planet[ 'high_zones' ] = 0;
			$Planet[ 'medium_zones' ] = 0;
			$Planet[ 'low_zones' ] = 0;
		}
		else
		{
			$Planet[ 'high_zones' ] = $Zone[ 'high_zones' ];
			$Planet[ 'medium_zones' ] = $Zone[ 'medium_zones' ];
			$Planet[ 'low_zones' ] = $Zone[ 'low_zones' ];
			$Planet[ 'best_zone' ] = $Zone[ 'best_zone' ];
		}
		
		Msg(
			'>> Planet {green}%3d{normal} - Captured: {green}%5s%%{normal} - High: {yellow}%2d{normal} - Medium: {yellow}%2d{normal} - Low: {yellow}%2d{normal} - Players: {yellow}%7s {green}(%s)',
			PHP_EOL,
			[
				$Planet[ 'id' ],
				number_format( $Planet[ 'state' ][ 'capture_progress' ] * 100, 2 ),
				$Planet[ 'high_zones' ],
				$Planet[ 'medium_zones' ],
				$Planet[ 'low_zones' ],
				number_format( $Planet[ 'state' ][ 'current_players' ] ),
				$Planet[ 'state' ][ 'name' ],
			]
		);
		
		if( $Zone !== false )
		{
			if( $Zone[ 'best_zone' ][ 'type' ] == 4 )
			{
				Msg( '{green}>> This planet has an uncaptured boss, selecting this planet...' );
				
				return $Planet;
			}
			
			$Planet[ 'sort_key' ] += (int)( $Planet[ 'state' ][ 'capture_progress' ] * 100 );
			
			if( $Planet[ 'low_zones' ] > 0 )
			{
				$Planet[ 'sort_key' ] += 99 - $Planet[ 'low_zones' ];
			}
			
			if( $Planet[ 'medium_zones' ] > 0 )
			{
				$Planet[ 'sort_key' ] += pow( 10, 2 ) * ( 99 - $Planet[ 'medium_zones' ] );
			}
			
			if( $Planet[ 'high_zones' ] > 0 )
			{
				$Planet[ 'sort_key' ] += pow( 10, 4 ) * ( 99 - $Planet[ 'high_zones' ] );
			}
		}
	}
	
	usort( $Planets, function( $a, $b )
	{
		return $b[ 'sort_key' ] - $a[ 'sort_key' ];
	} );
	
	$Planet = $Planets[ 0 ];
	
	Msg(
		'>> Next Zone is {yellow}' . $Planet[ 'best_zone' ][ 'zone_position' ] .
		'{normal} (Captured: {yellow}' . number_format( $Planet[ 'best_zone' ][ 'capture_progress' ] * 100, 2 ) . '%' .
		'{normal} - Difficulty: {yellow}' . GetNameForDifficulty( $Planet[ 'best_zone' ] ) .
		'{normal}) on Planet {green}' . $Planet[ 'id' ] .
		' (' . $Planet[ 'state' ][ 'name' ] . ')'
	);
	
	return $Planet;
}

function LeaveCurrentGame( $ShowMinorErrors, $Token, $FailSleep, $LeaveCurrentPlanet = 0 )
{
	do
	{
		$Data = SendPOST( 'ITerritoryControlMinigameService/GetPlayerInfo', 'access_token=' . $Token, $ShowMinorErrors );
		
		if( isset( $Data[ 'response' ][ 'active_zone_game' ] ) )
		{
			SendPOST( 'IMiniGameService/LeaveGame', 'access_token=' . $Token . '&gameid=' . $Data[ 'response' ][ 'active_zone_game' ], $ShowMinorErrors );
		}
		
		if( isset( $Data[ 'response' ][ 'active_boss_game' ] ) )
		{
			SendPOST( 'IMiniGameService/LeaveGame', 'access_token=' . $Token . '&gameid=' . $Data[ 'response' ][ 'active_boss_game' ], $ShowMinorErrors );
		}
	}
	while( !isset( $Data[ 'response' ][ 'score' ] ) && sleep( $FailSleep ) === 0 );
	
	if( !isset( $Data[ 'response' ][ 'active_planet' ] ) )
	{
		return 0;
	}
	
	$ActivePlanet = $Data[ 'response' ][ 'active_planet' ];
	
	if( $LeaveCurrentPlanet > 0 && $LeaveCurrentPlanet !== $ActivePlanet )
	{
		Msg( '== Leaving planet {green}' . $ActivePlanet . '{normal} because we want to be on {green}' . $LeaveCurrentPlanet );
		Msg( '== Time accumulated on planet {green}' . $ActivePlanet . '{normal}: {yellow}' . gmdate( 'H\h i\m s\s', $Data[ 'response' ][ 'time_on_planet' ] ) );
		
		echo PHP_EOL;
		
		SendPOST( 'IMiniGameService/LeaveGame', 'access_token=' . $Token . '&gameid=' . $ActivePlanet, $ShowMinorErrors );
	}
	
	return $ActivePlanet;
}

function SendPOST( $Method, $Data, $ShowMinorErrors )
{
	return ExecuteRequest( $ShowMinorErrors, $Method, 'https://community.steam-api.com/' . $Method . '/v0001/', $Data );
}

function SendGET( $Method, $Data, $ShowMinorErrors )
{
	return ExecuteRequest( $ShowMinorErrors, $Method, 'https://community.steam-api.com/' . $Method . '/v0001/?' . $Data );
}

function GetCurl( )
{
	global $c;
	
	if( isset( $c ) )
	{
		return $c;
	}
	
	$c = curl_init( );
	
	curl_setopt_array( $c, [
		CURLOPT_USERAGENT      => 'SalienSkScript (https://github.com/SkydeFR/SalienSkScript/)',
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING       => '', // Let curl decide best encoding on its own
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_HEADER         => 1,
		CURLOPT_CAINFO         => __DIR__ . '/cacert.pem',
		CURLOPT_HTTPHEADER     =>
		[
			'Connection: Keep-Alive',
			'Keep-Alive: timeout=300'
		],
	] );
	
	if( !empty( $_SERVER[ 'LOCAL_ADDRESS' ] ) )
	{
		curl_setopt( $c, CURLOPT_INTERFACE, $_SERVER[ 'LOCAL_ADDRESS' ] );
	}
	
	if( defined( 'CURL_HTTP_VERSION_2_0' ) )
	{
		curl_setopt( $c, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0 );
	}
	
	return $c;
}

function ExecuteRequest( $ShowMinorErrors, $Method, $URL, $Data = [] )
{
	$c = GetCurl( );
	
	curl_setopt( $c, CURLOPT_URL, $URL );
	
	if( !empty( $Data ) )
	{
		curl_setopt( $c, CURLOPT_POST, 1 );
		curl_setopt( $c, CURLOPT_POSTFIELDS, $Data );
	}
	else
	{
		curl_setopt( $c, CURLOPT_HTTPGET, 1 );
	}
	
	do
	{
		$Data = curl_exec( $c );
		
		$HeaderSize = curl_getinfo( $c, CURLINFO_HEADER_SIZE );
		$Header = substr( $Data, 0, $HeaderSize );
		$Data = substr( $Data, $HeaderSize );
		
		preg_match( '/[Xx]-eresult: ([0-9]+)/', $Header, $EResult ) === 1 ? $EResult = (int)$EResult[ 1 ] : $EResult = 0;
		
		if( $EResult !== 1 )
		{
			// EResult 93 : EResult.TimeNotSynced
			// EResult 84 : EResult.RateLimitExceeded
			// EResult 17 : "This is a boss zone. Update your cheats"
			// EResult 8 : Try to join a non active planet
			// EResult 2 : Try to join an overloaded zone
			//-----
			// EResult 27 : EResult.Expired
			// EResult 11 : EResult.InvalidState
			// EResult 10 : EResult.Busy
			// EResult 0 : EResult.Timeout
			
			if( $ShowMinorErrors || 
				(  $EResult !== 93 &&
				$EResult !== 84 &&
				$EResult !== 17 &&
				$EResult !== 8 &&
				$EResult !== 2 &&
				$EResult !== 27 &&
				$EResult !== 11 &&
				$EResult !== 10 &&
				$EResult !== 0 ) )
			{
				Msg( '{lightred}!! ' . $Method . ' failed - EResult: ' . $EResult . ' - ' . $Data );
			
				if( preg_match( '/^[Xx]-error_message: (?:.+)$/m', $Header, $ErrorMessage ) === 1 )
				{
					Msg( '{lightred}-- ' . $ErrorMessage[ 0 ] );
				}
			}
			
			if( $EResult === 15 && $Method === 'ITerritoryControlMinigameService/RepresentClan' )  // EResult.AccessDenied
			{
				echo PHP_EOL;
				
				Msg( '{green}-- If you want to support a steam group and represent it in game:' );
				Msg( '{green}-- Just join the group and set us as your clan on' );
				Msg( '{green}-- {yellow}https://steamcommunity.com/saliengame/play' );
				
				sleep( 10 );
			}
			else if( $EResult === 27 || $EResult === 11 ) // EResult.Expired || EResult.InvalidState
			{
				global $LastKnownPlanet;
				$LastKnownPlanet = 0;
			}
			else if( $EResult === 10 ) // EResult.Busy
			{
				$Data = '{}'; // Retry this exact request
				
				Msg( '{lightred}-- Steam is busy...' );
				
				sleep( 5 );
			}
			else if( $EResult === 0 ) // EResult.Timeout
			{
				if( $ShowMinorErrors )
				{
					Msg( '{lightred}-- This problem should resolve itself, wait for a couple of minutes' );
				}
			}
		}
		
		$Data = json_decode( $Data, true );
		$Data[ 'eresult' ] = $EResult;
	}
	while( !isset( $Data[ 'response' ] ) && sleep( 2 ) === 0 );
	
	return $Data;
}

function GetRepositoryScriptHash( &$RepositoryScriptETag, $LocalScriptHash )
{
	$c_r = curl_init( );
	
	$Time = time();
	$Time = $Time - ( $Time % 10 );
	
	curl_setopt_array( $c_r, [
		CURLOPT_URL            => 'https://raw.githubusercontent.com/SkydeFR/SalienSkScript/master/cheat.php?_=' . $Time,
		CURLOPT_USERAGENT      => 'SalienSkScript (https://github.com/SkydeFR/SalienSkScript/)',
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING       => 'gzip',
		CURLOPT_TIMEOUT        => 5,
		CURLOPT_CONNECTTIMEOUT => 5,
		CURLOPT_CAINFO         => __DIR__ . '/cacert.pem',
		CURLOPT_HEADER         => 1,
		CURLOPT_HTTPHEADER     =>
		[
			'If-None-Match: "' . $RepositoryScriptETag . '"'
		]
	] );
	
	$Data = curl_exec( $c_r );
	
	$HeaderSize = curl_getinfo( $c_r, CURLINFO_HEADER_SIZE );
	$Header = substr( $Data, 0, $HeaderSize );
	$Data = substr( $Data, $HeaderSize );
	
	curl_close( $c_r );
	
	if( preg_match( '/ETag: "([a-z0-9]+)"/', $Header, $ETag ) === 1 )
	{
		$RepositoryScriptETag = $ETag[ 1 ];
	}
	
	return strlen( $Data ) > 0 ? sha1( trim( $Data ) ) : $LocalScriptHash;
}

function GetAccountID( $SteamID )
{
	if( PHP_INT_SIZE === 8 )
	{
		return $SteamID & 0xFFFFFFFF;
	}
	else if( function_exists( 'gmp_and' ) )
	{
		return gmp_and( $SteamID, '0xFFFFFFFF' );
	}
	
	return 0;
}

function GetAccountName( $SteamID )
{
	// Strip names down to basic ASCII.
	$RegMask = '/[\x00-\x1F\x7F-\xFF]/';
	
	if( PHP_INT_SIZE === 8)
	{
		$xml = simplexml_load_file("http://steamcommunity.com/profiles/".$SteamID."/?xml=1");
	}
	else if( function_exists( 'gmp_and' ) )
	{
		$xml = simplexml_load_file("http://steamcommunity.com/profiles/".gmp_and( $SteamID )."/?xml=1");
	}
	
	if(!empty($xml))
	{
		return trim( preg_replace( $RegMask, '', $xml->steamID ) );
	}
	
	return 0;
}

function Msg( $Message, $EOL = PHP_EOL, $printf = [] )
{
	global $DisableColors;
	
	$Message = str_replace(
		[
			'{normal}',
			'{green}',
			'{yellow}',
			'{lightred}',
			'{teal}',
			'{background-blue}',
		],
		$DisableColors ? '' : [
			"\033[0m",
			"\033[0;32m",
			"\033[1;33m",
			"\033[1;31m",
			"\033[0;36m",
			"\033[37;44m",
		],
	$Message, $Count );
	
	if( $Count > 0 && !$DisableColors )
	{
		$Message .= "\033[0m";
	}
	
	$Message = '[' . date( 'H:i:s' ) . '] ' . $Message . $EOL;
	
	if( !empty( $printf ) )
	{
		array_unshift( $printf, $Message );
		call_user_func_array( 'printf', $printf );
	}
	else
	{
		echo $Message;
	}
}

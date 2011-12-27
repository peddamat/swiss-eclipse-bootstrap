<?php

// Safe Search and Replace on Database with Serialized Data v2.0.0

// This script is to solve the problem of doing database search and replace
// when developers have only gone and used the non-relational concept of
// serializing PHP arrays into single database columns.  It will search for all
// matching data on the database and change it, even if it's within a serialized
// PHP array.

// The big problem with serialised arrays is that if you do a normal DB
// style search and replace the lengths get mucked up.  This search deals with
// the problem by unserializing and reserializing the entire contents of the
// database you're working on.  It then carries out a search and replace on the
// data it finds, and dumps it back to the database.  So far it appears to work
// very well.  It was coded for our WordPress work where we often have to move
// large databases across servers, but I designed it to work with any database.
// Biggest worry for you is that you may not want to do a search and replace on
// every damn table - well, if you want, simply add some exclusions in the table
// loop and you'll be fine.  If you don't know how, you possibly shouldn't be
// using this script anyway.

// To use, simply configure the settings below and off you go.  I wouldn't
// expect the script to take more than a few seconds on most machines.

// BIG WARNING!  Take a backup first, and carefully test the results of this code.
// If you don't, and you vape your data then you only have yourself to blame.
// Seriously.  And if you're English is bad and you don't fully understand the
// instructions then STOP.  Right there.  Yes.  Before you do any damage.

// USE OF THIS SCRIPT IS ENTIRELY AT YOUR OWN RISK.  I/We accept no liability from its use.

// First Written 2009-05-25 by David Coveney of Interconnect IT Ltd (UK)
// http://www.davidcoveney.com or http://www.interconnectit.com
// and released under the WTFPL
// ie, do what ever you want with the code, and we take no responsibility for it OK?
// If you don't wish to take responsibility, hire us at Interconnect IT Ltd
// on +44 (0)151 331 5140 and we will do the work for you, but at a cost, minimum 1hr

// To view the WTFPL go to http://sam.zoy.org/wtfpl/ (WARNING: it's a little rude, if you're sensitive)

// Version 2.0.0 - returned to using unserialize function to check if string is serialized or not
//               - marked is_serialized_string function as deprecated
//       - changed form order to improve usability and make use on multisites a bit less scary
//       - changed to version 2, as really should have done when the UI was introduced
//               - added a recursive array walker to deal with serialized strings being stored in serialized strings. Yes, really.
//       - changes by James R Whitehead (kudos for recursive walker) and David Coveney 2011-08-26
// Version 1.0.2 - typos corrected, button text tweak - David Coveney / Robert O'Rourke
// Version 1.0.1 - styling and form added by James R Whitehead.

// Credits:  moz667 at gmail dot com for his recursive_array_replace posted at
//           uk.php.net which saved me a little time - a perfect sample for me
//           and seems to work in all cases.

// Internal version: $Id$


/*
 Helper functions.
*/
function icit_srdb_form_action( ) {
    global $step;
    echo basename( __FILE__ ) . '?step=' . intval( $step + 1 );
}

// Used to check the _post tables array
function check_table_array( $table = '' ){
    global $all_tables;
    return in_array( $table, $all_tables );
}

function icit_srdb_submit( $text = 'Submit', $warning = '' ){
    $warning = str_replace( "'", "\'", $warning ); ?>
    <input type="submit" class="button" value="<?php echo htmlentities( $text, ENT_QUOTES, 'UTF-8' ); ?>" <?php echo ! empty( $warning ) ? 'onclick="if (confirm(\'' . htmlentities( $warning, ENT_QUOTES, 'UTF-8' ) . '\')){return true;}return false;"' : ''; ?>/> <?php
}

function esc_html_attr( $string = '', $echo = false ){
    $output = htmlentities( $string, ENT_QUOTES, 'UTF-8' );
    if ( $echo )
        echo $output;
    else
        return $output;
}

function recursive_array_replace( $find, $replace, &$data ) {
    if ( is_array( $data ) ) {
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                recursive_array_replace( $find, $replace, $data[ $key ] );
            } else {
                // have to check if it's string to ensure no switching to string for booleans/numbers/nulls - don't need any nasty conversions
                if ( is_string( $value ) )
                    $data[ $key ] = str_replace( $find, $replace, $value );
            }
        }
    } else {
        if ( is_string( $data ) )
            $data = str_replace( $find, $replace, $data );
    }
}


function recursive_unserialise_replace( $from = '', $to = '', $data = '', $serialised = false ) {

    if ( is_string( $data ) && ( $unserialised = @unserialize( $data ) ) !== false ) {
        $data = recursive_unserialise_replace( $from, $to, $unserialised, true );
    }

    elseif ( is_array( $data ) ) {
        $_tmp = array( );
        foreach ( $data as $key => $value ) {
            $_tmp[ $key ] = recursive_unserialise_replace( $from, $to, $value, false );
        }

        $data = $_tmp;
        unset( $_tmp );
    }

    else {
        if ( is_string( $data ) )
            $data = str_replace( $from, $to, $data );
    }

    if ( $serialised )
        return serialize( $data );

    return $data;
}


function is_serialized_string( $data ) {     //this function is now deprecated and not used.
    // if it isn't a string, it isn't a serialized string
    if ( !is_string( $data ) )
        return false;
    $data = trim( $data );
    if ( preg_match( '/^s:[0-9]+:.*;$/s', $data ) ) // this should fetch all serialized strings
        return true;
    return false;
}

function icit_srdb_replacer( &$connection, $db = '', $search = '', $replace = '', $tables = array( ) ) {

    $report = array( 'tables' => 0,
                     'rows' => 0,
                     'change' => 0,
                     'updates' => 0,
                     'start' => microtime( ),
                     'end' => microtime( ),
                     'errors' => array( ),
                     );

    if ( is_array( $tables ) && ! empty( $tables ) ) {
        foreach( $tables as $table ) {
            $report[ 'tables' ]++;

            $columns = array( );

            // Get a lit of columns in this table
            $fields = mysql_db_query( $db, 'DESCRIBE ' . $table, $connection );
            while( $column = mysql_fetch_array( $fields ) )
                $columns[ $column[ 'Field' ] ] = $column[ 'Key' ] == 'PRI' ? true : false;

            // Count the number of rows we have in the table if large we'll split into blocks, This is a mod from Simon Wheatley
            $row_count = mysql_db_query( $db, 'SELECT COUNT(*) FROM ' . $table, $connection );
            $rows_result = mysql_fetch_array( $row_count );
            $row_count = $rows_result[ 0 ];
            if ( $row_count == 0 )
                continue;

            $page_size = 50000;
            $pages = ceil( $row_count / $page_size );

            for( $page = 0; $page < $pages; $page++ ) {

                $current_row = 0;
                $start = $page * $page_size;
                $end = $start + $page_size;
                // Grab the content of the table
                $data = mysql_db_query( $db, sprintf( 'SELECT * FROM %s LIMIT %d, %d', $table, $start, $end ), $connection );

                if ( ! $data )
                    $report[ 'errors' ][] = mysql_error( );

                while ( $row = mysql_fetch_array( $data ) ) {

                    $report[ 'rows' ]++; // Increment the row counter
                    $current_row++;

                    $update_sql = array( );
                    $where_sql = array( );
                    $upd = false;

                    foreach( $columns as $column => $primary_key ) {
                        $edited_data = $data_to_fix = $row[ $column ];

                        // Run a search replace on the data that'll respect the serialisation.
                        $edited_data = recursive_unserialise_replace( $search, $replace, $data_to_fix );

                        // Something was changed
                        if ( $edited_data != $data_to_fix ) {
                            $report[ 'change' ]++;
                            $update_sql[] = $column . ' = "' . mysql_real_escape_string( $edited_data ) . '"';
                            $upd = true;
                        }

                        if ( $primary_key )
                            $where_sql[] = $column . ' = "' . mysql_real_escape_string( $data_to_fix ) . '"';
                    }

                    if ( $upd && ! empty( $where_sql ) ) {
                        $sql = 'UPDATE ' . $table . ' SET ' . implode( ', ', $update_sql ) . ' WHERE ' . implode( ' AND ', array_filter( $where_sql ) );
                        $result = mysql_db_query( $db, $sql, $connection );
                        if ( ! $result )
                            $report[ 'errors' ][] = mysql_error( );
                        else
                            $report[ 'updates' ]++;

                    } elseif ( $upd ) {
                        $report[ 'errors' ][] = sprintf( '"%s" has no primary key, manual change needed on row %s.', $table, $current_row );
                    }

                }
            }
        }

    }
    $report[ 'end' ] = microtime( );

    return $report;
}


function icit_srdb_define_find( $filename = 'wp-config.php' ) {

    $filename = dirname( __FILE__ ) . '/../src/' . basename( $filename );

    if ( file_exists( $filename ) && is_file( $filename ) && is_readable( $filename ) ) {
        $file = @fopen( $filename, 'r' );
        $file_content = fread( $file, filesize( $filename ) );
        @fclose( $file );
    }

    preg_match_all( '/define\s*?\(\s*?([\'"])(DB_NAME|DB_USER|DB_PASSWORD|DB_HOST)\1\s*?,\s*?([\'"])([^\3]*?)\3\s*?\)\s*?;/si', $file_content, $defines );

    if ( ( isset( $defines[ 2 ] ) && ! empty( $defines[ 2 ] ) ) && ( isset( $defines[ 4 ] ) && ! empty( $defines[ 4 ] ) ) ) {
        foreach( $defines[ 2 ] as $key => $define ) {

            switch( $define ) {
                case 'DB_NAME':
                    $name = $defines[ 4 ][ $key ];
                    break;
                case 'DB_USER':
                    $user = $defines[ 4 ][ $key ];
                    break;
                case 'DB_PASSWORD':
                    $pass = $defines[ 4 ][ $key ];
                    break;
                case 'DB_HOST':
                    $host = $defines[ 4 ][ $key ];
                    break;
            }
        }
    }

    return array( $host, $name, $user, $pass );
}

/*
 Check and clean all vars, change the step we're at depending on the quality of
 the vars.
*/
$errors = array( );

// DB details
$host = isset( $_POST[ 'host' ] ) ? stripcslashes( $_POST[ 'host' ] ) : 'localhost';    // normally localhost, but not necessarily.
$data = isset( $_POST[ 'data' ] ) ? stripcslashes( $_POST[ 'data' ] ) : ''; // your database
$user = isset( $_POST[ 'user' ] ) ? stripcslashes( $_POST[ 'user' ] ) : ''; // your db userid
$pass = isset( $_POST[ 'pass' ] ) ? stripcslashes( $_POST[ 'pass' ] ) : ''; // your db password
// Search replace details
$srch = "http://localhost";
$rplc = "http://staging.talentgurus.net";
// Tables to scanned
$tables = array( "wp_options", "wp_postmeta", "wp_commentmeta", "wp_usermeta");

// Scan wp-config for the defines. We can't just include it as it will try and load the whole of wordpress.
if ( file_exists( dirname( __FILE__ ) . '/../src/wp-config.php' ) )
{
    list( $host, $data, $user, $pass ) = icit_srdb_define_find( 'wp-config.php' );
}

// Hack to allow passing commandline vars
if (count($argv) > 1)
{
  $args = explode(',',$argv[1]);
  $data = $args[0];
  $user = $args[1];
  $pass = $args[2];
  $srch = $args[3];
  $rplc = $args[4];
}

// Check the db connection else go back to step two.
$connection = mysql_connect( $host, $user, $pass );
if ( ! $connection ) {
    $errors[] = mysql_error( );
}

// Check and clean the tables array
//$tables = array_filter( $tables, 'check_table_array' );

@ set_time_limit( 60 * 10 );
// Try to push the allowed memory up, while we're at it
@ ini_set( 'memory_limit', '1024M' );

// Process the tables
if ( isset( $connection ) )
    $report = icit_srdb_replacer( $connection, $data, $srch, $rplc, $tables );

print_r($report);

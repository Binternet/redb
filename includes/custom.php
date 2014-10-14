<?php

# Here you can place your own custom functions

function general_convert( $section, $value_field = '', $value = '' ) {

	global $data_conv;

	# Note we're returning the string FALSE so we can see it in the db.
	if ( ! isset( $data_conv[$section] ) ) return 'FALSE';

	$value_field = ( empty( $value_field ) )
		? 'value'
		: $value_field;

	# Lookup
	foreach ( $data_conv[$section] as $key => $entry ) {
		if ( $entry['id'] == $value ) {
			return strtolower($entry[ $value_field ]);
		}
	}
}

/**
 * Callback after `users` table is complete
 * @return [type] [description]
 */
function complete_users() {

	$db = Redb::db_instance('new');

	// Set all gender to males who speaks english, we have such varity...
	$db->query("UPDATE users SET gender = 'm', language = 'english'");


}
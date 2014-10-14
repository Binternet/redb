<?php

function dbug($var,$stop=FALSE) {
	if ( ! class_exists('dBug') ) {
		require 'includes/libs/dbug.php';
	}

	new dBug($var);
	if ( $stop == TRUE ) die();

}
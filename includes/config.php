<?php

$config = array(
	# Old database
	'db_old'	=>	array(
		'host'		=>	'localhost',
		'db_name'	=>	'my_old_db',
		'username'	=>	'root',
		'password'	=>	''
		),
	# New database
	'db_new'	=>	array(
		'host'		=>	'localhost',
		'db_name'	=>	'my_new_db',
		'username'	=>	'root',
		'password'	=>	''
		)
);


# Helpers
require 'includes/helpers/general.php';
require 'includes/custom.php';
require 'includes/conversions.php';

# Libs
require 'includes/libs/dbug.php';
require 'includes/libs/redb.php';



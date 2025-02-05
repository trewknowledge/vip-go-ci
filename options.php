<?php

/*
 * Read settings from a options file in the
 * repository, but only allow certain options
 * to be configured.
 */
function vipgoci_options_read_repo_file(
	&$options,
	$repo_options_file_name,
	$options_overwritable
) {

	if ( false === $options[ 'phpcs-severity-repo-options-file' ] ) {
		vipgoci_log(
			'Skipping possibly overwriting options ' .
				'using data from repository settings file ' .
				'as this is disabled via command-line options',
			array(
				'phpcs_severity_repo_options_file'
					=> $options[ 'phpcs-severity-repo-options-file' ],
			)
		);

		return true;
	}

	vipgoci_log(
		'Reading options from repository, overwriting ' .
			'already set option values if applicable',
		array(
			'repo_owner'		=> $options['repo-owner'],
			'repo_name'		=> $options['repo-name'],
			'commit'		=> $options['commit'],
			'filename'		=> $repo_options_file_name,
			'options_overwritable'	=> $options_overwritable,
		)
	);


	/*
	 * Try to read options-file from
	 * repository, bail out of that fails.
	 */

	$repo_options_file_contents = vipgoci_gitrepo_fetch_committed_file(
		$options['repo-owner'],
		$options['repo-name'],
		$options['token'],
		$options['commit'],
		$repo_options_file_name,
		$options['local-git-repo']
	);

	if ( false === $repo_options_file_contents ) {
		vipgoci_log(
			'No options found, nothing further to do',
			array(
				'filename' => $repo_options_file_name,
			)
		);

		return false;
	}

	$repo_options_arr = json_decode(
		$repo_options_file_contents,
		true
	);

	if ( null === $repo_options_arr ) {
		vipgoci_log(
			'Options not parsable, nothing further to do',
			array(
				'filename'
					=> $repo_options_file_name,

				'repo_options_arr'
					=> $repo_options_arr,

				'repo_options_file_contents'
					=> $repo_options_file_contents,
			)
		);


		return false;
	}


	/*
	 * Actually set/overwrite values. Keep account of what we set
	 * and to what value, log it at the end.
	 */
	$options_read = array();

	foreach(
		$options_overwritable as
			$option_overwritable_name =>
			$option_overwritable_conf
	) {
		/*
		 * Detect possible issues with
		 * the arguments given, or value not defined
		 * in the options-file.
		 */
		if (
			( ! isset(
				$repo_options_arr[
					$option_overwritable_name
				]
			) )
			||
			( ! isset(
				$option_overwritable_conf['type']
			) )
		) {
			continue;
		}


		$do_skip = false;

		if ( 'integer' === $option_overwritable_conf['type'] ) {
			if ( ! isset(
				$option_overwritable_conf['valid_values']
			) ) {
				$do_skip = true;
			}

			if ( ! in_array(
				$repo_options_arr[
					$option_overwritable_name
				],
				$option_overwritable_conf['valid_values'],
				true
			) ) {
				$do_skip = true;
			}
		}

		else {
			$do_skip = true;
		}


		if ( true === $do_skip ) {
			vipgoci_log(
				'Found invalid value for option in option-file, or invalid arguments passed internally',
				array(
					'option_overwritable_name'
						=> $option_overwritable_name,

					'option_overwritable_conf'
						=> $option_overwritable_conf,

					'repo_options_arr[' . $option_overwritable_name .' ]'
						=> $repo_options_arr[ $option_overwritable_name ],
				)
			);

			continue;
		}


		$options[
			$option_overwritable_name
		]
		=
		$options_read[
			$option_overwritable_name
		]
		=
		$repo_options_arr[
			$option_overwritable_name
		];
	}

	vipgoci_log(
		'Set or overwrote the following options',
		$options_read
	);

	return true;
}

/*
 * Handle boolean parameters given on the command-line.
 *
 * Will set a default value for the given parameter name,
 * if no value is set. Will then proceed to check if the
 * value given is a boolean and will then convert the value
 * to a boolean-type, and finally set it in $options.
 */

function vipgoci_option_bool_handle(
	&$options,
	$parameter_name,
	$default_value
) {

	/* If no default is given, set it */
	if ( ! isset( $options[ $parameter_name ] ) ) {
		$options[ $parameter_name ] = $default_value;
	}

	/* Check if the gien value is a false or true */
	if (
		( $options[ $parameter_name ] !== 'false' ) &&
		( $options[ $parameter_name ] !== 'true' )
	) {
		vipgoci_sysexit(
			'Parameter --' . $parameter_name .
				' has to be either false or true',
			array(),
			VIPGOCI_EXIT_USAGE_ERROR
		);
	}

	/* Convert the given value to a boolean type value */
	if ( $options[ $parameter_name ] === 'false' ) {
		$options[ $parameter_name ] = false;
	}

	else {
		$options[ $parameter_name ] = true;
	}
}

/*
 * Handle integer parameters given on the command-line.
 *
 * Will set a default value for the given parameter name,
 * if no value is set. Will then proceed to check if the
 * value given is an integer-value, then forcibly convert
 * it to integer-value to make sure it is of that type,
 * then check if it is in a list of allowable values.
 * If any of these fail, it will exit the program with an error.
 */

function vipgoci_option_integer_handle(
	&$options,
	$parameter_name,
	$default_value,
	$allowed_values = null
) {
	/* If no value is set, set the default value */
	if ( ! isset( $options[ $parameter_name ] ) ) {
		$options[ $parameter_name ] = $default_value;
	}

	/* Make sure it is a numeric */
	if ( ! is_numeric( $options[ $parameter_name ] ) ) {
		vipgoci_sysexit(
			'Usage: Parameter --' . $parameter_name . ' is not ' .
				'an integer-value.',
			array(),
			VIPGOCI_EXIT_USAGE_ERROR
		);
	}

	/* Forcibly convert to integer-value */
	$options[ $parameter_name ] =
		(int) $options[ $parameter_name ];

	/*
	 * Check if value is in range
	 */

	if (
		( null !== $allowed_values )
		&&
		( ! in_array(
			$options[ $parameter_name ],
			$allowed_values,
			true
		) )
	) {
		vipgoci_sysexit(
			'Parameter --' . $parameter_name . ' is out ' .
				'of allowable range.',
			array(),
			VIPGOCI_EXIT_USAGE_ERROR
		);
	}

}

/*
 * Handle array-like option parameters given on the command line
 *
 * Parses the parameter, turns it into a real array,
 * makes sure forbidden values are not contained in it.
 * Does not return the result, but rather alters
 * $options directly.
 *
 * Allows for array-item separator to be specified.
 */
function vipgoci_option_array_handle(
	&$options,
	$option_name,
	$default_value = array(),
	$forbidden_value = null,
	$array_separator = ','
) {
	if ( ! isset( $options[ $option_name ] ) ) {
		$options[ $option_name ] = $default_value;
	}

	else {
		$options[ $option_name ] = explode(
			$array_separator,
			strtolower(
				$options[ $option_name ]
			)
		);

		if ( ! empty( $forbidden_value ) ) {
			if ( in_array(
				$forbidden_value,
				$options[ $option_name ],
				true
			) ) {
				vipgoci_sysexit(
					'Parameter --' .
						$option_name . ' ' .
						'can not contain \'' .
						$forbidden_value .
						'\' as one of ' .
						'the values',
					array(),
					VIPGOCI_EXIT_USAGE_ERROR
				);
			}
		}
	}
}


/*
 * Handle parameter that expects the value
 * of it to be a file. Allow a default value
 * to be set if none is set.
 */

function vipgoci_option_file_handle(
	&$options,
	$option_name,
	$default_value = null
) {

	if (
		( ! isset( $options[ $option_name ] ) ) &&
		( null !== $default_value )
	) {
		$options[ $option_name ] = $default_value;
	}

	else if (
		( ! isset( $options[ $option_name ] ) ) ||
		( ! is_file( $options[ $option_name ] ) )
	) {
		vipgoci_sysexit(
			'Parameter --' . $option_name .
				' has to be a valid path',
			array(),
			VIPGOCI_EXIT_USAGE_ERROR
		);
	}
}

/**
 * Handle parameter that we expect to be a URL.
 *
 * If the parameter is not empty, and is not really
 * a URL (not starting with http:// or https://),
 * exit with error. If empty, sets a default.
 *
 * @codeCoverageIgnore
 */

function vipgoci_option_url_handle(
	&$options,
	$option_name,
	$default_value
) {
	/*
	 * If not set, assume default value.
	 */
	if (
		( ! isset( $options[ $option_name ] ) ) ||
		( empty( $options[ $option_name ] ) )
	) {
		$options[ $option_name ] = $default_value;
	}

	/*
	 * If not default value, check if it looks like an URL,
	 * and if so, use it, but if not, exit with error.
	 */
	if ( $default_value !== $options[ $option_name ] ) {
		$options[ $option_name ] = trim(
			$options[ $option_name ]
		);

		if (
			( 0 !== strpos(
				$options[ $option_name ],
				'http://'
			) )
			&&
			( 0 !== strpos(
				$options[ $option_name ],
				'https://'
			) )
		) {
			vipgoci_sysexit(
				'Option --' . $option_name . ' should ' .
					'be an URL',
				array(
				),
				VIPGOCI_EXIT_USAGE_ERROR
			);
		}
	}
}

/*
 * Handle parameter that we expect to contain teams,
 * either as an ID (numeric) or a string (slug).
 *
 * Will check if the teams are valid, removing invalid ones,
 * transforming strings into IDs, and reconstruct the option
 * afterwards.
 */

function vipgoci_option_teams_handle(
	&$options,
	$option_name
) {
	if (
		( ! isset( $options[ $option_name ] ) ) ||
		( ! is_array( $options[ $option_name ] ) )
	) {
		$options[ $option_name ] = array();
	}

	if ( empty( $options[ $option_name ] ) ) {
		return;
	}

	$options[ $option_name ] = array_map(
		'vipgoci_sanitize_string',
		$options[ $option_name ]
	);


	$teams_info = vipgoci_github_org_teams(
		$options['token'],
		$options['repo-owner'],
		null,
		'slug'
	);

	foreach(
		$options[ $option_name ] as
			$team_id_key =>	$team_id_value
	) {
		$team_id_value_original = $team_id_value;

		/*
		 * If a string, transform team_id_value into integer ID
		 * for team.
		 */
		if (
			( ! is_numeric( $team_id_value ) ) &&
			( ! empty( $teams_info[ $team_id_value ] ) )
		) {
			$team_id_value = $teams_info[ $team_id_value ][0]->id;
		}

		/*
		 * If $team_id_value is a numeric,
		 * the team exists, so put in
		 * the integer-value in the options.
		 */
		if ( is_numeric( $team_id_value ) ) {
			$options
				[ $option_name ]
				[ $team_id_key ] = (int) $team_id_value;
		}

		/*
		 * Something failed; we might have
		 * failed to transform $team_id_value into
		 * a numeric representation (ID) and/or
		 * it may have been invalid, so remove
		 * it from the options array.
		 */

		else {
			vipgoci_log(
				'Invalid team ID found in ' .
				'--' . $option_name .
				' parameter; ignoring it.',
				array(
					'team_id' => $team_id_value,
					'team_id_original' => $team_id_value_original,
				)
			);

			unset(
				$options
					[ $option_name ]
					[ $team_id_key ]
			);
		}
	}

	/* Reconstruct array from the previous one */
	$options[ $option_name ] =
		array_values( array_unique(
			$options[ $option_name ]
		) );

	unset( $teams_info );
	unset( $team_id_key );
	unset( $team_id_value );
	unset( $team_id_value_original );
}



<?php

/*
 * Run PHPCS for the file specified, using the
 * appropriate standards. Return the results.
 */

function vipgoci_phpcs_do_scan(
	$filename_tmp,
	$phpcs_path,
	$phpcs_standard,
	$phpcs_sniffs_exclude,
	$phpcs_severity,
	$phpcs_runtime_set
) {
	/*
	 * Run PHPCS from the shell, making sure we escape everything.
	 *
	 * Feed PHPCS the temporary file specified by our caller.
	 */
	$cmd = sprintf(
		'%s %s --standard=%s --severity=%s --report=%s',
		escapeshellcmd( 'php' ),
		escapeshellcmd( $phpcs_path ),
		escapeshellarg( $phpcs_standard ),
		escapeshellarg( $phpcs_severity ),
		escapeshellarg( 'json' )
	);

	/*
	 * If we have sniffs to exclude, add them
	 * to the command-line string.
	 */

	if ( ! empty( $phpcs_sniffs_exclude ) ) {
		$cmd .= sprintf(
			' --exclude=%s',
			escapeshellarg( $phpcs_sniffs_exclude )
		);
	}

	/*
	 * If we have specific runtime-set values,
	 * put them in them now.
	 */
	if ( ! empty( $phpcs_runtime_set ) ) {
		foreach(
			$phpcs_runtime_set as
				$phpcs_runtime_set_value
		) {
			$cmd .= sprintf(
				' --runtime-set %s %s',
				escapeshellarg( $phpcs_runtime_set_value[0] ),
				escapeshellarg( $phpcs_runtime_set_value[1] )
			);
		}
	}

	/*
	 * Lastly, append the target filename
	 * to the command-line string.
	 */
	$cmd .= sprintf(
		' %s',
		escapeshellarg( $filename_tmp )
	);

	$cmd .= ' 2>&1';

	vipgoci_log(
		'Running PHPCS now',
		array(
			'cmd' => $cmd,
		),
		0
	);

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_START, 'phpcs_cli' );

	$result = shell_exec( $cmd );

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_STOP, 'phpcs_cli' );

	return $result;
}

function vipgoci_phpcs_scan_single_file(
	$options,
	$file_name
) {
	$file_contents = vipgoci_gitrepo_fetch_committed_file(
		$options['repo-owner'],
		$options['repo-name'],
		$options['token'],
		$options['commit'],
		$file_name,
		$options['local-git-repo']
	);

	$file_extension = vipgoci_file_extension(
		$file_name
	);

	if ( empty( $file_extension ) ) {
		$file_extension = null;
	}

	$temp_file_name = vipgoci_save_temp_file(
		'phpcs-scan-',
		$file_extension,
		$file_contents
	);

	vipgoci_log(
		'About to PHPCS-scan file',
		array(
			'repo_owner' => $options['repo-owner'],
			'repo_name' => $options['repo-name'],
			'commit_id' => $options['commit'],
			'filename' => $file_name,
			'file_extension' => $file_extension,
			'temp_file_name' => $temp_file_name,
		)
	);


	$file_issues_str = vipgoci_phpcs_do_scan(
		$temp_file_name,
		$options['phpcs-path'],
		$options['phpcs-standard'],
		$options['phpcs-sniffs-exclude'],
		$options['phpcs-severity'],
		$options['phpcs-runtime-set']
	);

	/* Get rid of temporary file */
	unlink( $temp_file_name );

	$file_issues_arr_master = json_decode(
		$file_issues_str,
		true
	);

	return array(
		'file_issues_arr_master'	=> $file_issues_arr_master,
		'file_issues_str'		=> $file_issues_str,
		'temp_file_name'		=> $temp_file_name,
	);
}


/**
 * Dump output of scan-analysis to a file,
 * if possible.
 *
 * @codeCoverageIgnore
 */

function vipgoci_phpcs_scan_output_dump( $output_file, $data ) {
	if (
		( is_file( $output_file ) ) &&
		( ! is_writeable( $output_file ) )
	) {
		vipgoci_log(
			'File ' .
				$output_file .
				' is not writeable',
			array()
		);
	} else {
		file_put_contents(
			$output_file,
			json_encode(
				$data,
				JSON_PRETTY_PRINT
			),
			FILE_APPEND
		);
	}
}

/*
 * Scan a particular commit which should live within
 * a particular repository on GitHub, and use the specified
 * access-token to gain access.
 */
function vipgoci_phpcs_scan_commit(
	$options,
	&$commit_issues_submit,
	&$commit_issues_stats
) {
	$repo_owner = $options['repo-owner'];
	$repo_name  = $options['repo-name'];
	$commit_id  = $options['commit'];
	$github_token = $options['token'];

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_START, 'phpcs_scan_commit' );

	vipgoci_log(
		'About to PHPCS-scan repository',

		array(
			'repo_owner' => $repo_owner,
			'repo_name' => $repo_name,
			'commit_id' => $commit_id,
		)
	);


	/*
	 * First, figure out if a .gitmodules
	 * file was added or modified; if so,
	 * we need to scan the relevant sub-module(s)
	 * specifically.
	 */

	$commit_info = vipgoci_github_fetch_commit_info(
		$repo_owner,
		$repo_name,
		$commit_id,
		$github_token,
		array(
			'file_extensions'
				=> array( 'gitmodules' ),

			'status'
				=> array( 'added', 'modified' ),
		)
	);


	if ( ! empty( $commit_info->files ) ) {
		// FIXME: Do something about the .gitmodule file
	}



	// Fetch list of all Pull-Requests which the commit is a part of
	$prs_implicated = vipgoci_github_prs_implicated(
		$repo_owner,
		$repo_name,
		$commit_id,
		$github_token,
		$options['branches-ignore']
	);


	/*
	 * Get list of all files affected by
	 * each Pull-Request implicated by the commit.
	 */

	vipgoci_log(
		'Fetching list of all files affected by each Pull-Request ' .
			'implicated by the commit',

		array(
			'repo_owner' => $repo_owner,
			'repo_name' => $repo_name,
			'commit_id' => $commit_id,
		)
	);

	$pr_item_files_changed = array();
	$pr_item_files_changed['all'] = array();

	foreach ( $prs_implicated as $pr_item ) {
		/*
		 * Make sure that the PR is defined in the array
		 */
		if ( ! isset( $pr_item_files_changed[ $pr_item->number ] ) ) {
			$pr_item_files_changed[ $pr_item->number ] = [];
		}

		/*
		 * Get list of all files changed
		 * in this Pull-Request.
		 */

		$pr_item_files_tmp = vipgoci_github_diffs_fetch(
			$repo_owner,
			$repo_name,
			$github_token,
			$pr_item->base->sha,
			$commit_id,
			false, // exclude renamed files
			false, // exclude removed files
			false, // exclude permission changes
			array(
				'file_extensions' =>
					/*
					 * If SVG-checks are enabled,
					 * include it in the file-extensions
					 */
					array_merge(
						array( 'php', 'js', 'twig' ),
						( $options['svg-checks'] ?
							array( 'svg' ) :
							array()
						)
					),
				'skip_folders' =>
					$options['skip-folders'],
			)
		);


		foreach ( $pr_item_files_tmp as $pr_item_file_name => $_tmp ) {
			if ( in_array(
				$pr_item_file_name,
				$pr_item_files_changed['all'],
				true
			) === false ) {
				$pr_item_files_changed['all'][] =
					$pr_item_file_name;
			}

			if ( in_array(
				$pr_item_file_name,
				$pr_item_files_changed[ $pr_item->number ],
				true
			) === false ) {
				$pr_item_files_changed[
					$pr_item->number
				][] = $pr_item_file_name;
			}
		}
	}


	$files_issues_arr = array();

	/*
	 * Loop through each altered file in all the Pull-Requests,
	 * use PHPCS to scan for issues, save the issues; they will
	 * be processed in the next step.
	 */

	vipgoci_log(
		'About to PHPCS-scan all files affected by any of the ' .
			'Pull-Requests',

		array(
			'repo_owner' => $repo_owner,
			'repo_name' => $repo_name,
			'commit_id' => $commit_id,
			'all_files_changed_by_prs' =>
				$pr_item_files_changed['all'],
		)
	);

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_START, 'phpcs_scan_single_file' );

	foreach ( $pr_item_files_changed['all'] as $file_name ) {
		/*
		 * Loop through each file affected by
		 * the commit.
		 */

		$file_extension = vipgoci_file_extension(
			$file_name
		);

		/*
		 * If a SVG file, scan using a
		 * custom internal function, otherwise
		 * use PHPCS.
		 *
		 * However, only do this if SVG-checks
		 * is enabled.
		 */
		$scanning_func =
			(
				( 'svg' === $file_extension ) &&
				( $options['svg-checks'] )
			) ?
				'vipgoci_svg_scan_single_file' :
				'vipgoci_phpcs_scan_single_file';

		$tmp_scanning_results = $scanning_func(
			$options,
			$file_name
		);

		$file_issues_arr_master =
			$tmp_scanning_results['file_issues_arr_master'];

		$file_issues_str =
			$tmp_scanning_results['file_issues_str'];

		$temp_file_name =
			$tmp_scanning_results['temp_file_name'];

		/*
		 * Keep statistics on number of lines
		 * and files we scan.
		 */
		vipgoci_stats_per_file(
			$options,
			$file_name,
			'scanned'
		);

		/*
		 * Do sanity-checking
		 */

		if (
			( null === $file_issues_arr_master ) ||
			( ! isset( $file_issues_arr_master['totals'] ) ) ||
			( ! isset( $file_issues_arr_master['files'] ) )
		) {
			vipgoci_log(
				'Failed parsing output from PHPCS',
				array(
					'repo_owner' => $repo_owner,
					'repo_name' => $repo_name,
					'commit_id' => $commit_id,
					'file_issues_arr_master' =>
						$file_issues_arr_master,
					'file_issues_str' =>
						$file_issues_str,
				),
				0,
				true // log to IRC
			);

			/*
			 * No further processing in case of an error.
			 *
			 * Set an empty array just in case to avoid warnings.
			 */
			$files_issues_arr[ $file_name ] = array();

			continue;
		}

		unset( $file_issues_str );

		/*
		 * Make sure items in $file_issues_arr_master have
		 * 'level' key and value.
		 */
		$file_issues_arr_master = array_map(
			function( $item ) {
				$item['level'] = $item['type'];

				return $item;
			},
			$file_issues_arr_master
				['files']
				[ $temp_file_name ]
				['messages']
		);

		/*
		 * Remove any duplicate issues.
		 */
		$file_issues_arr_master = vipgoci_issues_filter_duplicate(
			$file_issues_arr_master
		);

		$files_issues_arr[ $file_name ] = $file_issues_arr_master;

		/*
		 * Output scanning-results if requested
		 */

		if ( ! empty( $options['output'] ) ) {
			vipgoci_phpcs_scan_output_dump(
				$options['output'],
				array(
					'repo_owner'	=> $repo_owner,
					'repo_name'	=> $repo_name,
					'commit_id'	=> $commit_id,
					'filename'	=> $file_name,
					'issues'	=> $file_issues_arr_master,
				)
			);
		}

		/*
		 * Get rid of data, and
		 * attempt to garbage-collect.
		 */
		vipgoci_log(
			'Cleaning up after scanning of file...',
			array()
		);

		unset( $file_contents );
		unset( $file_extension );
		unset( $temp_file_name );
		unset( $file_issues_arr_master );
		unset( $file_issues_str );

		gc_collect_cycles();
	}

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_STOP, 'phpcs_scan_single_file' );

	/*
	 * Loop through each Pull-Request implicated,
	 * get comments made on GitHub already,
	 * then filter out any PHPCS-issues irrelevant
	 * as they are not due to any commit that is part
	 * of the Pull-Request, and skip any PHPCS-issue
	 * already reported. Report the rest, if any.
	 */

	vipgoci_log(
		'Figuring out which comment(s) to submit to GitHub, if any',
		array(
			'repo_owner' => $repo_owner,
			'repo_name' => $repo_name,
			'commit_id' => $commit_id,
		)
	);


	foreach ( $prs_implicated as $pr_item ) {
		vipgoci_log(
			'Preparing to process PHPCS scanned files in ' .
				'Pull-Request, to construct results ' .
				'to be submitted',
			array(
				'repo_owner'    => $repo_owner,
				'repo_name'     => $repo_name,
				'commit_id'     => $commit_id,
				'pr_number'     => $pr_item->number,
				'files_changed' =>
					$pr_item_files_changed[ $pr_item->number ]
			)
		);


		/*
		 * Get all commits related to the current
		 * Pull-Request.
		 */
		$pr_item_commits = vipgoci_github_prs_commits_list(
			$repo_owner,
			$repo_name,
			$pr_item->number,
			$github_token
		);


		/*
		 * Loop through each file, get a
		 * 'git blame' log for the file, then
		 * filter out issues stemming
		 * from commits that are not a
		 * part of the current Pull-Request.
		 */

		foreach (
			$pr_item_files_changed[ $pr_item->number ] as
				$_tmp => $file_name
			) {

			/*
			 * Get blame log for file
			 */
			$file_blame_log = vipgoci_gitrepo_blame_for_file(
				$commit_id,
				$file_name,
				$options['local-git-repo']
			);

			$file_changed_lines = vipgoci_patch_changed_lines(
				$repo_owner,
				$repo_name,
				$github_token,
				$pr_item->base->sha,
				$commit_id,
				$file_name
			);

			$file_relative_lines = @array_flip(
				$file_changed_lines
			);


			/*
			 * Filter the issues we found
			 * previously in this file; remove
			 * the ones that the are not found
			 * in the blame-log (meaning that
			 * they are due to commits outside of
			 * the Pull-Request).
			 */

			$file_issues_arr_filtered = vipgoci_issues_filter_irrellevant(
				$file_name,
				$files_issues_arr,
				$file_blame_log,
				$pr_item_commits,
				$file_relative_lines
			);

			/*
			 * Collect all the issues that
			 * we need to submit about
			 */

			foreach( $file_issues_arr_filtered as
				$file_issue_val_key =>
				$file_issue_val_item
			) {
				$commit_issues_submit[
					$pr_item->number
				][] = array(
					'type'		=> VIPGOCI_STATS_PHPCS,

					'file_name'	=>
						$file_name,

					'file_line'	=>
						$file_relative_lines[
							$file_issue_val_item[
								'line'
						]
					],

					'issue'		=>
						$file_issue_val_item,
				);

				/*
				 * Collect statistics on
				 * number of warnings/errors
				 */

				$commit_issues_stats[
					$pr_item->number
				][
					strtolower(
						$file_issue_val_item[
							'level'
						]
					)
				]++;
			}
		}

		unset( $pr_item_commits );
		unset( $file_blame_log );
		unset( $file_changed_lines );
		unset( $file_relative_lines );
		unset( $file_issues_arr_filtered );

		gc_collect_cycles();
	}

	/*
	 * Clean up a bit
	 */
	vipgoci_log(
		'Cleaning up after PHPCS-scanning...',
		array()
	);

	unset( $prs_implicated );
	unset( $pr_item_files_changed );

	gc_collect_cycles();

	vipgoci_runtime_measure( VIPGOCI_RUNTIME_STOP, 'phpcs_scan_commit' );
}


<?php

require_once( __DIR__ . '/IncludesForTests.php' );

use PHPUnit\Framework\TestCase;

final class LintLintGetIssuesTest extends TestCase {
	var $options_php = array(
		'php-path'	=> null,
	);

	protected function setUp() {
		vipgoci_unittests_get_config_values(
			'lint-scan',
			$this->options_php
		);
	}

	protected function tearDown() {
		$this->options_php = null;
	}

	/**
	 * @covers ::vipgoci_lint_get_issues
	 */
	public function testLintGetIssues1() {
		$options_test = vipgoci_unittests_options_test(
			$this->options_php,
			array(  ),
			$this
		);

		if ( -1 === $options_test ) {
			return;
		}

		$php_file_path = vipgoci_save_temp_file(
			'test-lint-get-issues-1',
			'php',
			'<?php ' . PHP_EOL . 'echo "foo";' . PHP_EOL
		);

		$php_file_name = pathinfo(
			$php_file_path,
			PATHINFO_FILENAME
		);

		vipgoci_unittests_output_suppress();

		$lint_issues = vipgoci_lint_do_scan(
			$this->options_php['php-path'],
			$php_file_path
		);

		$lint_issues_parsed = vipgoci_lint_get_issues(
			$php_file_name,
			$php_file_name,
			$lint_issues
		);

		vipgoci_unittests_output_unsuppress();

		$this->assertEquals(
			array(
			),
			$lint_issues_parsed
		);
	}

	/**
	 * @covers ::vipgoci_lint_get_issues
	 */
	public function testLintDoScan2() {
		$options_test = vipgoci_unittests_options_test(
			$this->options_php,
			array(  ),
			$this
		);

		if ( -1 === $options_test ) {
			return;
		}

		$php_file_path = vipgoci_save_temp_file(
			'test-lint-get-issues-2',
			'php',
			'<?php ' . PHP_EOL . 'echo "foo"' . PHP_EOL
		);

		$php_file_name = pathinfo(
			$php_file_path,
			PATHINFO_FILENAME
		);

		vipgoci_unittests_output_suppress();

		$lint_issues = vipgoci_lint_do_scan(
			$this->options_php['php-path'],
			$php_file_path
		);

		$lint_issues_parsed = vipgoci_lint_get_issues(
			'php-file-name.php',
			$php_file_path,
			$lint_issues
		);

		vipgoci_unittests_output_unsuppress();

		$this->assertEquals(
			array(
				3 => array(
					array(
						'message' 	=> "syntax error, unexpected end of file, expecting ',' or ';'",
						'level'		=> 'ERROR',
					)
				)
			),
			$lint_issues_parsed
		);
	}
}

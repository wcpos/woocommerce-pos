<?php
/**
 * Template Validator Class.
 *
 * Validates template code for security and syntax.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 */

namespace WCPOS\WooCommercePOS\Templates;

use WP_Error;

class Validator {
	/**
	 * List of dangerous PHP functions that should be flagged.
	 *
	 * @var array
	 */
	private static $dangerous_functions = array(
		'eval',
		'exec',
		'system',
		'shell_exec',
		'passthru',
		'proc_open',
		'popen',
		'curl_exec',
		'curl_multi_exec',
		'parse_ini_file',
		'show_source',
		'file_put_contents',
		'fopen',
		'fwrite',
		'unlink',
		'rmdir',
		'mkdir',
		'chmod',
		'chown',
		'chgrp',
		'touch',
		'symlink',
		'link',
		'tempnam',
		'tmpfile',
		'move_uploaded_file',
		'phpinfo',
		'assert',
		'create_function',
		'call_user_func',
		'call_user_func_array',
	);

	/**
	 * Validate template code.
	 *
	 * @param string $content  Template content.
	 * @param string $language Template language (php, javascript).
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function validate( string $content, string $language ) {
		if ( 'php' === $language ) {
			return self::validate_php( $content );
		}

		if ( 'javascript' === $language ) {
			return self::validate_javascript( $content );
		}

		return new WP_Error(
			'invalid_language',
			__( 'Invalid template language.', 'woocommerce-pos' )
		);
	}

	/**
	 * Sanitize template content.
	 *
	 * @param string $content  Template content.
	 * @param string $language Template language.
	 *
	 * @return string Sanitized content.
	 */
	public static function sanitize( string $content, string $language ): string {
		if ( 'php' === $language ) {
			// For PHP, we don't want to sanitize too much as it would break the code
			// Just ensure proper slashing for storage
			return $content;
		}

		if ( 'javascript' === $language ) {
			// For JavaScript, similarly we want to preserve the code structure
			return $content;
		}

		return $content;
	}

	/**
	 * Check if user is allowed to edit templates.
	 *
	 * @return bool True if user can edit templates, false otherwise.
	 */
	public static function can_edit_templates(): bool {
		return current_user_can( 'manage_woocommerce_pos' );
	}

	/**
	 * Log template validation attempt.
	 *
	 * @param int    $template_id Template ID.
	 * @param string $content     Template content.
	 * @param mixed  $result      Validation result.
	 *
	 * @return void
	 */
	public static function log_validation( int $template_id, string $content, $result ): void {
		if ( ! \defined( 'WCPOS_TEMPLATE_VALIDATION_LOG' ) || ! WCPOS_TEMPLATE_VALIDATION_LOG ) {
			return;
		}

		$user      = wp_get_current_user();
		$log_entry = array(
			'timestamp'    => current_time( 'mysql' ),
			'user_id'      => $user->ID,
			'user_login'   => $user->user_login,
			'template_id'  => $template_id,
			'result'       => is_wp_error( $result ) ? $result->get_error_message() : 'success',
			'content_hash' => md5( $content ),
		);

		// Store in option (you might want to use a custom table for better performance)
		$logs   = get_option( 'wcpos_template_validation_logs', array() );
		$logs[] = $log_entry;

		// Keep only last 100 entries
		if ( \count( $logs ) > 100 ) {
			$logs = \array_slice( $logs, -100 );
		}

		update_option( 'wcpos_template_validation_logs', $logs );
	}

	/**
	 * Validate PHP template code.
	 *
	 * @param string $content PHP template content.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private static function validate_php( string $content ) {
		// Check for syntax errors
		$syntax_check = self::check_php_syntax( $content );
		if ( is_wp_error( $syntax_check ) ) {
			return $syntax_check;
		}

		// Check for dangerous functions
		$dangerous_check = self::check_dangerous_functions( $content );
		if ( is_wp_error( $dangerous_check ) ) {
			return $dangerous_check;
		}

		/*
		 * Filters the PHP template validation result.
		 *
		 * @param true|WP_Error $result  Validation result.
		 * @param string        $content Template content.
		 *
		 * @since 1.8.0
		 *
		 * @hook woocommerce_pos_validate_php_template
		 */
		return apply_filters( 'woocommerce_pos_validate_php_template', true, $content );
	}

	/**
	 * Validate JavaScript template code.
	 *
	 * @param string $content JavaScript template content.
	 *
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private static function validate_javascript( string $content ) {
		// Basic validation for JavaScript
		// Check for obviously dangerous patterns
		$dangerous_patterns = array(
			'/eval\s*\(/i',
			'/Function\s*\(/i',
			'/setTimeout\s*\(\s*["\']/',
			'/setInterval\s*\(\s*["\']/',
		);

		foreach ( $dangerous_patterns as $pattern ) {
			if ( preg_match( $pattern, $content ) ) {
				return new WP_Error(
					'dangerous_javascript',
					\sprintf(
						// translators: %s: pattern that was found
						__( 'Template contains potentially dangerous JavaScript code: %s', 'woocommerce-pos' ),
						$pattern
					)
				);
			}
		}

		/*
		 * Filters the JavaScript template validation result.
		 *
		 * @param true|WP_Error $result  Validation result.
		 * @param string        $content Template content.
		 *
		 * @since 1.8.0
		 *
		 * @hook woocommerce_pos_validate_javascript_template
		 */
		return apply_filters( 'woocommerce_pos_validate_javascript_template', true, $content );
	}

	/**
	 * Check PHP syntax.
	 *
	 * @param string $content PHP code to check.
	 *
	 * @return true|WP_Error True if syntax is valid, WP_Error otherwise.
	 */
	private static function check_php_syntax( string $content ) {
		// Use php -l to check syntax if available
		if ( \function_exists( 'exec' ) && ! \defined( 'DISABLE_TEMPLATE_SYNTAX_CHECK' ) ) {
			$temp_file = tempnam( sys_get_temp_dir(), 'wcpos_template_' );
			file_put_contents( $temp_file, $content );

			$output     = array();
			$return_var = 0;
			exec( 'php -l ' . escapeshellarg( $temp_file ) . ' 2>&1', $output, $return_var );

			unlink( $temp_file );

			if ( 0 !== $return_var ) {
				return new WP_Error(
					'php_syntax_error',
					\sprintf(
						// translators: %s: error message
						__( 'PHP syntax error: %s', 'woocommerce-pos' ),
						implode( "\n", $output )
					)
				);
			}
		}

		// Fallback: Basic check for unclosed PHP tags
		$open_tags  = substr_count( $content, '<?php' ) + substr_count( $content, '<?' );
		$close_tags = substr_count( $content, '?>' );

		// Note: It's valid to have more open tags than close tags (files can end without closing tag)
		// But if we have more close tags, that's definitely an error
		if ( $close_tags > $open_tags ) {
			return new WP_Error(
				'php_syntax_error',
				__( 'PHP syntax error: Mismatched PHP tags.', 'woocommerce-pos' )
			);
		}

		return true;
	}

	/**
	 * Check for dangerous PHP functions.
	 *
	 * @param string $content PHP code to check.
	 *
	 * @return true|WP_Error True if no dangerous functions found, WP_Error otherwise.
	 */
	private static function check_dangerous_functions( string $content ) {
		/**
		 * Filters the list of dangerous PHP functions.
		 *
		 * @param array $functions List of dangerous function names.
		 *
		 * @since 1.8.0
		 *
		 * @hook woocommerce_pos_template_dangerous_functions
		 */
		$dangerous = apply_filters( 'woocommerce_pos_template_dangerous_functions', self::$dangerous_functions );

		// Remove comments from content to avoid false positives
		$content_without_comments = preg_replace( '/\/\*[\s\S]*?\*\/|\/\/.*$/m', '', $content );

		foreach ( $dangerous as $function ) {
			// Check for function calls
			if ( preg_match( '/\b' . preg_quote( $function, '/' ) . '\s*\(/i', $content_without_comments ) ) {
				/**
				 * Filters whether to allow dangerous functions in templates.
				 *
				 * @param bool   $allow    Whether to allow the dangerous function.
				 * @param string $function Function name.
				 * @param string $content  Template content.
				 *
				 * @since 1.8.0
				 *
				 * @hook woocommerce_pos_template_allow_dangerous_function
				 */
				$allow = apply_filters( 'woocommerce_pos_template_allow_dangerous_function', false, $function, $content );

				if ( ! $allow ) {
					return new WP_Error(
						'dangerous_function',
						\sprintf(
							// translators: %s: function name
							__( 'Template contains dangerous function: %s(). This function is not allowed for security reasons.', 'woocommerce-pos' ),
							$function
						)
					);
				}
			}
		}

		return true;
	}
}

<?php
/**
 * StaticMockerHack class file.
 */

namespace Automattic\WooCommerce\Testing\Tools\CodeHacking\Hacks;

use Exception;

/**
 * Hack to mock public static methods and properties.
 *
 * How to use:
 *
 * 1. Invoke 'StaticMockerHack::initialize' once, passing an array with the names of the classes
 * that can be mocked.
 *
 * 2. Invoke 'CodeHacker::add_hack(StaticMockerHack::get_hack_instance())' once.
 *
 * 3. Use 'add_method_mocks' in tests as needed to register callbacks to be executed instead of the functions, e.g.:
 *
 * StaticMockerHack::add_method_mocks(
 * [
 *     'SomeClass' => [
 *         'some_method' => function($some_arg) {
 *              return 'foo' === $some_arg ? 'bar' : SomeClass::some_method($some_arg);
 *         }
 *     ]
 * ]);
 *
 * 1 and 2 must be done during the unit testing bootstrap process.
 *
 * Note that unless the tests directory is included in the hacking via 'CodeHacker::initialize'
 * (and they shouldn't!), test code files aren't hacked, therefore the original functions are always
 * executed inside tests (and thus the above example won't stack-overflow).
 */
final class StaticMockerHack extends CodeHack {
	/**
	 * An associative array of class name => array of class methods.
	 *
	 * @var array
	 */
	private $mockable_classes;

	/**
	 * @var StaticMockerHack Holds the only existing instance of the class.
	 */
	private static $instance;

	/**
	 * @var array Associative array of class name => associative array of method name => callback.
	 */
	private $method_mocks = array();

	/**
	 * StaticMockerHack constructor.
	 *
	 * @param array $mockable_classes An associative array of class name => array of class methods.
	 */
	private function __construct( $mockable_classes ) {
		$this->mockable_classes = $mockable_classes;
	}

	/**
	 * Handler for undefined static methods on this class, it invokes the mock for the method if both the class and the method are registered, or the original method in the original class if not.
	 *
	 * @param string $name      Name of the method.
	 * @param array  $arguments Arguments for the function.
	 *
	 * @throws Exception Invalid method name.
	 *
	 * @return mixed The return value from the invoked callback or method.
	 */
	public static function __callStatic( $name, $arguments ) {
		preg_match( '/invoke__(.+)__for__(.+)/', $name, $matches );
		if ( empty( $matches ) ) {
			throw new Exception( 'Invalid method ' . __CLASS__ . "::{$name}" );
		}

		$class_name  = $matches[2];
		$method_name = $matches[1];

		if ( \array_key_exists( $class_name, self::$instance->method_mocks ) && \array_key_exists( $method_name, self::$instance->method_mocks[ $class_name ] ) ) {
			return \call_user_func_array( self::$instance->method_mocks[ $class_name ][ $method_name ], $arguments );
		}

		return \call_user_func_array( "{$class_name}::{$method_name}", $arguments );
	}

	/**
	 * Initializes the class.
	 *
	 * @param array $mockable_classes An associative array of class name => array of class methods.
	 *
	 * @throws Exception $mockable_functions is not an array or is empty.
	 */
	public static function initialize( $mockable_classes ): void {
		if ( ! \is_array( $mockable_classes ) || empty( $mockable_classes ) ) {
			throw new Exception( 'StaticMockerHack::initialize:: $mockable_classes must be a non-empty associative array of class name => array of class methods.' );
		}

		self::$instance = new self( $mockable_classes );
	}

	/**
	 * Hacks code by replacing eligible method invocations with an invocation a static method on this class composed from the class and the method names.
	 *
	 * @param string $code The code to hack.
	 * @param string $path The path of the file containing the code to hack.
	 *
	 * @return string The hacked code.
	 */
	public function hack( $code, $path ) {
		return $this->hack_tokens( $this->tokenize( $code ) );
	}

	/**
	 * Rewrite a token stream containing mockable static method calls.
	 *
	 * Kept separate from tokenization so both PHP 7.4 and PHP 8 token shapes can
	 * be covered on every CI leg.
	 *
	 * @param array $tokens PHP source tokens.
	 * @return string Rewritten PHP source.
	 */
	private function hack_tokens( array $tokens ): string {
		$count                          = \count( $tokens );
		$code                           = '';
		$previous_token_is_ns_separator = false;

		for ( $i = 0; $i < $count; $i++ ) {
			$current_token = $tokens[ $i ];

			// PHP 7.4 splits qualified names into T_STRING/T_NS_SEPARATOR chains.
			// Mock only unqualified short names; PHP 8's T_NAME_* tokens already skip
			// this branch.
			if ( ! $previous_token_is_ns_separator && $this->is_token_of_type( $current_token, T_STRING ) && \in_array( $current_token[1], $this->mockable_classes, true ) ) {
				$class_name = $current_token[1];
				$next_token = $tokens[ $i + 1 ] ?? null;

				if ( null !== $next_token && $this->is_token_of_type( $next_token, T_DOUBLE_COLON ) ) {
					$member_token = $tokens[ $i + 2 ] ?? null;

					// Only static METHOD CALLS can be routed through the mock apparatus.
					// Constant fetches (Class::CONST), the ::class magic constant, and
					// static property access are compile-time constructs with no callable
					// to intercept; rewriting them into StaticMockerHack::invoke__X__for__Y
					// produces an undefined class-constant fatal. Confirm a call by peeking
					// past the member (skipping whitespace) for an opening parenthesis.
					$is_method_call = null !== $member_token && ! $this->is_token_of_type( $member_token, T_CLASS );
					if ( $is_method_call ) {
						$j = $i + 3;
						while ( $j < $count && $this->is_token_of_type( $tokens[ $j ], T_WHITESPACE ) ) {
							$j++;
						}
						$is_method_call = $j < $count && '(' === $tokens[ $j ];
					}

					if ( $is_method_call ) {
						$called_member = $this->token_to_string( $member_token );
						$code          .= '\\' . __CLASS__ . "::invoke__{$called_member}__for__{$class_name}";
						$i             += 2; // Consumed the class name, '::' and the member.
						$previous_token_is_ns_separator = false;
						continue;
					}
				}

				// Reference to the source class, but not a mockable method call
				// (bare reference, constant, ::class, or static property). Emit it
				// unchanged and let the loop process the following tokens normally.
				$code                          .= $this->token_to_string( $current_token );
				$previous_token_is_ns_separator = false;
				continue;
			}

			// Not a reference to a source class.
			$code                          .= $this->token_to_string( $current_token );
			$previous_token_is_ns_separator = $this->is_token_of_type( $current_token, T_NS_SEPARATOR );
		}

		return $code;
	}

	/**
	 * Register method mocks.
	 *
	 * @param array $mocks Mocks as an associative array of class name => associative array of method name => mock method with the same arguments as the original method.
	 *
	 * @throws Exception Invalid input.
	 */
	public function register_method_mocks( $mocks ): void {
		$exception_text = 'StaticMockerHack::register_method_mocks: $mocks must be an associative array of class name => associative array of method name => callable.';

		if ( ! \is_array( $mocks ) ) {
			throw new Exception( $exception_text );
		}

		foreach ( $mocks as $class_name => $class_mocks ) {
			if ( ! \is_string( $class_name ) || ! \is_array( $class_mocks ) ) {
				throw new Exception( $exception_text );
			}
			foreach ( $class_mocks as $method_name => $method_mock ) {
				if ( ! \is_string( $method_name ) || ! \is_callable( $method_mock ) ) {
					throw new Exception( $exception_text );
				}
				if ( ! \in_array( $class_name, $this->mockable_classes, true ) ) {
					throw new Exception( "FunctionsMockerHack::add_function_mocks: Can't mock methods of the '$class_name' class since it isn't in the list of mockable classes supplied to 'initialize'." );
				}

				$this->method_mocks[ $class_name ][ $method_name ] = $method_mock;
			}
		}
	}

	/**
	 * Register method mocks.
	 *
	 * @param array $mocks Mocks as an associative array of class name => associative array of method name => mock method with the same arguments as the original method.
	 *
	 * @throws Exception Invalid input.
	 */
	public static function add_method_mocks( $mocks ): void {
		self::$instance->register_method_mocks( $mocks );
	}

	/**
	 * Unregister all the registered method mocks.
	 */
	public function reset(): void {
		$this->method_mocks = array();
	}

	/**
	 * Get the only existing instance of this class. 'get_instance' is not used to avoid conflicts since that's a widely used method name.
	 *
	 * @return StaticMockerHack The only existing instance of this class.
	 */
	public static function get_hack_instance() {
		return self::$instance;
	}
}

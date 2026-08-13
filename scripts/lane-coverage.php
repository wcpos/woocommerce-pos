#!/usr/bin/env php
<?php
const SCAN_DIR          = 'tests';
const OUTPUT_DIR        = 'tests/lane-coverage';
const CURRENT_V1_ROUTES = array( 'wcpos/v1/products/variations' );
function usage() { echo "Usage: php scripts/lane-coverage.php [--write|--check|--json|--warnings|--compare=<base.json>] [--root=<path>]\n"; }
function fail( $message, $code = 2 ) { fwrite( STDERR, $message . "\n" ); exit( $code ); }
function parse_cli( $arguments ) {
	$mode      = null;
	$root      = dirname( __DIR__ );
	$root_arg  = null;
	$compare   = null;
	$show_help = empty( $arguments );
	foreach ( $arguments as $argument ) {
		if ( '--help' === $argument || '-h' === $argument ) {
			$show_help = true;
		} elseif ( in_array( $argument, array( '--write', '--check', '--json', '--warnings' ), true ) ) {
			if ( null !== $mode ) { fail( 'Choose exactly one mode.' ); }
			$mode = substr( $argument, 2 );
		} elseif ( 0 === strpos( $argument, '--compare=' ) ) {
			if ( null !== $mode ) { fail( 'Choose exactly one mode.' ); }
			$mode    = 'compare';
			$compare = substr( $argument, 10 );
		} elseif ( 0 === strpos( $argument, '--root=' ) ) {
			$root_arg = substr( $argument, 7 );
			$root     = $root_arg;
		} else {
			fail( 'Unknown argument: ' . $argument );
		}
	}
	if ( $show_help || null === $mode ) {
		usage(); exit( 0 );
	}
	$real_root = realpath( $root );
	if ( false === $real_root || ! is_dir( $real_root ) ) { fail( 'Root directory does not exist: ' . $root ); }
	if ( 'compare' === $mode && ! is_file( $compare ) ) { fail( 'Baseline JSON not found: ' . $compare ); }
	return array( $mode, $real_root, $root_arg, $compare );
}
function lanes_from_text( $text, $reference = false ) {
	$lanes = array();
	$map   = $reference
		? array( '\\API\\V1\\' => 'v1', '\\API\\V2\\' => 'v2' )
		: array( 'wcpos/v1' => 'v1', 'wcpos/v2' => 'v2', 'wc/v3' => 'wc3', 'wp/v2' => 'wp2' );
	foreach ( $map as $needle => $lane ) {
		if ( false !== strpos( $text, $needle ) ) {
			$lanes[ $lane ] = true;
		}
	}
	return $lanes;
}
function sorted_lanes( $sets ) {
	$lanes = array();
	foreach ( $sets as $set ) {
		$lanes += $set;
	}
	$lanes = array_keys( $lanes );
	sort( $lanes, SORT_STRING );
	return $lanes;
}
function previous_significant_token( $tokens, $index ) {
	for ( $index--; $index >= 0; $index-- ) {
		if ( ! is_array( $tokens[ $index ] ) || ! in_array( $tokens[ $index ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			return $tokens[ $index ];
		}
	}
	return null;
}
function next_significant_index( $tokens, $index ) {
	for ( $index++; $index < count( $tokens ); $index++ ) {
		if ( ! is_array( $tokens[ $index ] ) || ! in_array( $tokens[ $index ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			return $index;
		}
	}
	return null;
}
function literal_text_from_call_argument( $tokens, $open_index, $argument_index, &$literal_indexes ) {
	$text     = '';
	$argument = 0;
	$depth    = 0;
	$literal_indexes = array();
	for ( $index = $open_index + 1; $index < count( $tokens ); $index++ ) {
		$token = $tokens[ $index ];
		if ( '(' === $token ) { $depth++; }
		elseif ( ')' === $token && 0 === $depth ) { break; }
		elseif ( ')' === $token ) { $depth--; }
		elseif ( ',' === $token && 0 === $depth ) { $argument++; }
		elseif ( $argument === $argument_index && is_array( $token )
			&& in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
			$text                      .= trim( $token[1], "'\"" );
			$literal_indexes[ $index ] = true;
		}
	}
	return $text;
}
function name_token_ids() {
	$ids = array( T_STRING, T_NS_SEPARATOR );
	foreach ( array( 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE' ) as $name ) {
		if ( defined( $name ) ) { $ids[] = constant( $name ); }
	}
	return $ids;
}
function add_finding( &$classes, $class_index, $method, $lanes, $literal = null ) {
	if ( null === $class_index || empty( $lanes ) ) {
		return;
	}
	if ( null !== $method && $method['test'] ) {
		$target =& $classes[ $class_index ]['methods'][ $method['index'] ];
		$target['lanes'] += $lanes;
		if ( null !== $literal && isset( $lanes['v1'] ) ) { $target['v1_literals'][ $literal ] = true; }
	} else {
		$classes[ $class_index ]['lanes'] += $lanes;
		if ( null !== $literal && isset( $lanes['v1'] ) ) { $classes[ $class_index ]['v1_literals'][ $literal ] = true; }
	}
}
function add_warning( &$classes, $class_index, $method, $pattern, $file, $line ) {
	if ( null === $class_index ) { return; }
	$warning = array( 'pattern' => $pattern, 'file' => $file, 'line' => $line );
	if ( null !== $method && $method['test'] ) {
		$classes[ $class_index ]['methods'][ $method['index'] ]['warnings'][] = $warning;
	} else {
		$classes[ $class_index ]['warnings'][] = $warning;
	}
}
function scan_file( $absolute_file, $relative_file ) {
	$tokens            = token_get_all( file_get_contents( $absolute_file ) );
	$name_ids          = name_token_ids();
	$classes           = array();
	$imports           = array();
	$namespace         = '';
	$namespace_stack   = array();
	$class_stack       = array();
	$method_stack      = array();
	$pending_namespace = null;
	$pending_class     = null;
	$pending_method    = null;
	$pending_docblock  = null;
	$route_literal_tokens = array();
	$brace_depth       = 0;
	$count             = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		$id    = is_array( $token ) ? $token[0] : null;
		if ( T_DOC_COMMENT === $id ) {
			$pending_docblock = $token[1];
			continue;
		}
		if ( T_NAMESPACE === $id ) {
			$old  = $namespace;
			$name = '';
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $name_ids, true ) ) {
					$name .= $tokens[ $j ][1];
				} elseif ( ';' === $tokens[ $j ] || '{' === $tokens[ $j ] ) {
					$namespace = trim( $name, '\\' );
					if ( '{' === $tokens[ $j ] ) {
						$pending_namespace = array( 'old' => $old, 'name' => $namespace );
					}
					break;
				}
			}
		}
		$current_class  = empty( $class_stack ) ? null : end( $class_stack );
		$current_method = empty( $method_stack ) ? $pending_method : end( $method_stack );
		if ( T_USE === $id && null === $current_class && null === $pending_class ) {
			$statement = '';
		for ( $j = $i + 1; $j < $count && ';' !== $tokens[ $j ]; $j++ ) {
				$statement .= is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
			}
			if ( ! isset( $imports[ $namespace ] ) ) {
				$imports[ $namespace ] = array();
			}
			$imports[ $namespace ] += lanes_from_text( $statement, true );
		}
		if ( T_CLASS === $id ) {
			$previous = previous_significant_token( $tokens, $i );
			if ( is_array( $previous ) && T_DOUBLE_COLON === $previous[0] ) {
				continue; // The token in Foo::class is not a declaration.
			}
			$skip     = is_array( $previous ) && T_NEW === $previous[0];
			$name     = null;
			if ( ! $skip ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
						$name = $tokens[ $j ][1];
						break;
					}
					if ( '(' === $tokens[ $j ] || '{' === $tokens[ $j ] ) { break; }
				}
			}
			$class_index = null;
			if ( null !== $name ) {
				$class_index = count( $classes );
				$classes[]   = array(
					'name' => ltrim( $namespace . '\\' . $name, '\\' ), 'short' => $name,
					'namespace' => $namespace, 'file' => $relative_file,
					'lanes' => array(), 'v1_literals' => array(), 'warnings' => array(), 'methods' => array(),
				);
			}
			$pending_class    = array( 'index' => $class_index );
			$pending_docblock = null;
		}
		$current_class = null !== $pending_class ? $pending_class : ( empty( $class_stack ) ? null : end( $class_stack ) );
		if ( T_FUNCTION === $id && null !== $current_class && null !== $current_class['index'] && $brace_depth === $current_class['depth'] ) {
			$method_name = null;
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
					$method_name = $tokens[ $j ][1];
					break;
				}
				if ( '(' === $tokens[ $j ] ) { break; }
			}
			if ( null !== $method_name ) {
				$is_test = 0 === strpos( $method_name, 'test' ) || ( null !== $pending_docblock && false !== strpos( $pending_docblock, '@test' ) );
				$method_index = null;
				if ( $is_test ) {
					$method_index = count( $classes[ $current_class['index'] ]['methods'] );
					$classes[ $current_class['index'] ]['methods'][] = array(
						'name' => $method_name, 'lanes' => array(), 'v1_literals' => array(), 'warnings' => array(),
						'stub_sites' => array(), 'has_assertion' => false, 'has_response_access' => false,
					);
				}
				$pending_method = array( 'class' => $current_class['index'], 'index' => $method_index, 'test' => $is_test );
			}
			$pending_docblock = null;
		}
		$current_class  = null !== $pending_class ? $pending_class : ( empty( $class_stack ) ? null : end( $class_stack ) );
		$current_method = empty( $method_stack ) ? $pending_method : end( $method_stack );
		$class_index    = null === $current_class ? null : $current_class['index'];
		if ( null !== $current_method ) { $class_index = $current_method['class']; }
		if ( ( T_CONSTANT_ENCAPSED_STRING === $id || T_ENCAPSED_AND_WHITESPACE === $id ) && ! isset( $route_literal_tokens[ $i ] ) ) {
			add_finding( $classes, $class_index, $current_method, lanes_from_text( $token[1] ), $token[1] );
		}
		if ( is_array( $token ) && in_array( $id, $name_ids, true ) ) {
			$previous = previous_significant_token( $tokens, $i );
			if ( null === $previous || ! is_array( $previous ) || ! in_array( $previous[0], $name_ids, true ) ) {
				$name = '';
				for ( $j = $i; $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $name_ids, true ); $j++ ) {
					$name .= $tokens[ $j ][1];
				}
				add_finding( $classes, $class_index, $current_method, lanes_from_text( $name, true ) );
			}
		}
		if ( T_STRING === $id ) {
			$next     = next_significant_index( $tokens, $i );
			$previous = previous_significant_token( $tokens, $i );
			$is_call  = null !== $next && '(' === $tokens[ $next ];
			$is_method_call = $is_call && is_array( $previous )
				&& in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true );
			if ( $is_call && preg_match( '/^wp_rest_[a-z_]+_request$/', $token[1] ) ) {
				$literal = literal_text_from_call_argument( $tokens, $next, 0, $literal_indexes );
				$route_literal_tokens += $literal_indexes;
				$lanes   = lanes_from_text( $literal );
				add_finding( $classes, $class_index, $current_method, empty( $lanes ) ? array( 'unresolved' => true ) : $lanes, empty( $lanes ) ? null : $literal );
			}
			if ( $is_method_call && in_array( $token[1], array( 'wcpos_dispatch_request', 'register_routes', 'register_hooks', 'init_hooks' ), true ) ) {
				add_warning( $classes, $class_index, $current_method, 'self-installed-hook', $relative_file, $token[2] );
			}
			if ( null !== $current_method && $current_method['test'] && $is_call ) {
				$method =& $classes[ $class_index ]['methods'][ $current_method['index'] ];
				if ( $is_method_call && 0 === stripos( $token[1], 'assert' ) ) { $method['has_assertion'] = true; }
				if ( in_array( $token[1], array( 'get_data', 'get_status' ), true ) ) { $method['has_response_access'] = true; }
				unset( $method );
			}
		}
		if ( T_NEW === $id && null !== $current_method ) {
			$name_index = next_significant_index( $tokens, $i );
			$name       = '';
			for ( $j = $name_index; null !== $j && $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], $name_ids, true ); $j++ ) {
				$name .= $tokens[ $j ][1];
			}
			$constructor = next_significant_index( $tokens, $j - 1 );
			if ( $current_method['test'] && 'WP_REST_Response' === ltrim( $name, '\\' ) && null !== $constructor && '(' === $tokens[ $constructor ] ) {
				$classes[ $class_index ]['methods'][ $current_method['index'] ]['stub_sites'][ $token[2] ] = true;
			} elseif ( 'WP_REST_Request' === ltrim( $name, '\\' ) && null !== $constructor && '(' === $tokens[ $constructor ] ) {
				$after_open = next_significant_index( $tokens, $constructor );
				if ( null !== $after_open && ')' === $tokens[ $after_open ] ) {
					// A bare `new WP_REST_Request()` carries no route at all: it is a
					// payload/stub object handed to serializers, not a dispatch. It
					// contributes NO lane signal — `unresolved` is reserved for routes
					// that exist but cannot be read from literals, and counting bare
					// constructors there misclassifies pure-unit observers.
				} else {
					$literal = literal_text_from_call_argument( $tokens, $constructor, 1, $literal_indexes );
					$route_literal_tokens += $literal_indexes;
					$lanes   = lanes_from_text( $literal );
					add_finding( $classes, $class_index, $current_method, empty( $lanes ) ? array( 'unresolved' => true ) : $lanes, empty( $lanes ) ? null : $literal );
				}
			}
		}
		if ( T_VARIABLE === $id && '$GLOBALS' === $token[1] && null !== $current_method && $current_method['test'] ) {
			$open = next_significant_index( $tokens, $i );
			$key  = null === $open ? null : next_significant_index( $tokens, $open );
			$close = null === $key ? null : next_significant_index( $tokens, $key );
			$equals = null === $close ? null : next_significant_index( $tokens, $close );
			if ( null !== $equals && '[' === $tokens[ $open ] && is_array( $tokens[ $key ] )
				&& T_CONSTANT_ENCAPSED_STRING === $tokens[ $key ][0] && false !== stripos( $tokens[ $key ][1], 'response' )
				&& ']' === $tokens[ $close ] && '=' === $tokens[ $equals ] ) {
				$classes[ $class_index ]['methods'][ $current_method['index'] ]['stub_sites'][ $token[2] ] = true;
			}
		}
		if ( T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
			$brace_depth++; // Balance the plain } emitted for interpolated strings.
		} elseif ( '{' === $token ) {
			$brace_depth++;
			if ( null !== $pending_namespace ) {
				$namespace_stack[] = array( 'depth' => $brace_depth, 'old' => $pending_namespace['old'] );
				$pending_namespace = null;
			}
			if ( null !== $pending_class ) {
				$pending_class['depth'] = $brace_depth;
				$class_stack[]          = $pending_class;
				$pending_class          = null;
			}
			if ( null !== $pending_method ) {
				$pending_method['depth'] = $brace_depth;
				$method_stack[]          = $pending_method;
				$pending_method          = null;
			}
			$pending_docblock = null;
		} elseif ( '}' === $token ) {
			if ( ! empty( $method_stack ) && end( $method_stack )['depth'] === $brace_depth ) { array_pop( $method_stack ); }
			if ( ! empty( $class_stack ) && end( $class_stack )['depth'] === $brace_depth ) { array_pop( $class_stack ); }
			if ( ! empty( $namespace_stack ) && end( $namespace_stack )['depth'] === $brace_depth ) {
				$namespace = array_pop( $namespace_stack )['old'];
			}
			$brace_depth--;
			$pending_docblock = null;
		} elseif ( ';' === $token ) {
			$pending_method   = null;
			$pending_docblock = null;
		}
	}
	foreach ( $classes as &$class ) {
		if ( isset( $imports[ $class['namespace'] ] ) ) {
			$class['lanes'] += $imports[ $class['namespace'] ];
		}
	}
	unset( $class );
	return $classes;
}
function scan_classes( $root ) {
	$scan_root = $root . '/' . SCAN_DIR;
	if ( ! is_dir( $scan_root ) ) { fail( 'Scan directory does not exist: ' . SCAN_DIR ); }
	$files    = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $scan_root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) { $files[] = $file->getPathname(); }
	}
	sort( $files, SORT_STRING );
	$classes = array();
	foreach ( $files as $file ) {
		$relative = str_replace( '\\', '/', substr( $file, strlen( $root ) + 1 ) );
		$classes  = array_merge( $classes, scan_file( $file, $relative ) );
	}
	return $classes;
}
function read_annotations( $root ) {
	$file = $root . '/' . OUTPUT_DIR . '/annotations.json';
	if ( ! is_file( $file ) ) { return array( 'classes' => array(), 'cases' => array() ); }
	$data = json_decode( file_get_contents( $file ), true );
	if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) { fail( 'Invalid annotations JSON: ' . json_last_error_msg() ); }
	foreach ( array( 'classes', 'cases' ) as $section ) {
		if ( ! isset( $data[ $section ] ) ) { $data[ $section ] = array(); }
		elseif ( ! is_array( $data[ $section ] ) ) { fail( 'Annotation section "' . $section . '" must be an object.' ); }
	}
	return $data;
}
function is_non_current_v1_literal( $literal ) {
	$path  = ltrim( trim( $literal, " \t\n\r\0\x0B'\"" ), '/' );
	$query = strpos( $path, '?' );
	if ( false !== $query ) { $path = substr( $path, 0, $query ); }
	foreach ( CURRENT_V1_ROUTES as $route ) {
		if ( $path === $route ) { return false; }
	}
	return true;
}
function build_inventory( $classes, $annotations ) {
	$allowed_verdicts = array( 'covered', 'gap', 'unverified', 'legacy-pin', 'unreviewed' );
	$class_names      = array();
	$case_keys        = array();
	$cases            = array();
	foreach ( $classes as $class ) {
		$class_names[ $class['name'] ] = true;
		$behavior = isset( $annotations['classes'][ $class['name'] ]['behavior'] )
			? $annotations['classes'][ $class['name'] ]['behavior'] : str_replace( '_', ' ', $class['short'] );
		$class_lanes = sorted_lanes( array( $class['lanes'] ) );
		foreach ( $class['methods'] as $method ) {
			$key        = $class['name'] . '::' . $method['name'];
			$annotation = isset( $annotations['cases'][ $key ] ) ? $annotations['cases'][ $key ] : array();
			$verdict    = isset( $annotation['verdict'] ) ? $annotation['verdict'] : 'unreviewed';
			if ( ! in_array( $verdict, $allowed_verdicts, true ) ) { fail( 'Unknown verdict "' . $verdict . '" for annotation ' . $key ); }
			$own_lanes  = sorted_lanes( array( $method['lanes'] ) );
			$lanes      = sorted_lanes( array( $class['lanes'], $method['lanes'] ) );
			$v1_literals = $class['v1_literals'] + $method['v1_literals'];
			// A v1 signal is treated as legacy unless we can positively prove otherwise, i.e.
			// unless every v1 route literal we saw is one the app still calls. A v1 lane with no
			// route literal at all (it came from a `use ...\API\V1\...` import, or a route built
			// at runtime) is NOT provably current, so it stays legacy. Erring towards reporting
			// is deliberate: a false positive costs a line in the inventory, a false negative is
			// the silent "already ported" claim this whole artifact exists to prevent.
			$has_legacy = empty( $v1_literals );
			foreach ( array_keys( $v1_literals ) as $literal ) {
				$has_legacy = $has_legacy || is_non_current_v1_literal( $literal );
			}
			// `v1_only` is a positive claim and stays provable: we saw a v1 signal and no
			// current-lane signal. `unresolved` never grants it — calling a case "v1" when the
			// route was assembled at runtime would put a false statement in the inventory, and an
			// artifact that misstates one row does not get trusted about the other 435.
			$on_current  = in_array( 'v2', $lanes, true ) || in_array( 'wc3', $lanes, true );
			$v1_only     = in_array( 'v1', $lanes, true ) && ! $on_current && $has_legacy;
			// Both categories are "not proven to cover current behaviour", and the ratchet guards
			// their union — otherwise a new test could slip past the gate simply by building its
			// route out of variables.
			$unresolved  = in_array( 'unresolved', $lanes, true ) && ! $on_current && ! $v1_only;
			$warnings = array_merge( $class['warnings'], $method['warnings'] );
			if ( $method['has_assertion'] && $method['has_response_access'] ) {
				foreach ( array_keys( $method['stub_sites'] ) as $line ) {
					$warnings[] = array( 'pattern' => 'asserted-stubbed-response', 'file' => $class['file'], 'line' => $line );
				}
			}
			usort( $warnings, function ( $a, $b ) {
				$result = strcmp( $a['pattern'], $b['pattern'] );
				return 0 === $result ? $a['line'] - $b['line'] : $result;
			} );
			$cases[] = array(
				'key' => $key, 'file' => $class['file'], 'class' => $class['name'], 'method' => $method['name'],
				'behavior' => $behavior, 'own_lanes' => $own_lanes, 'class_lanes' => $class_lanes,
				'lanes' => $lanes, 'v1_only' => $v1_only, 'unresolved' => $unresolved, 'verdict' => $verdict,
				'note' => isset( $annotation['note'] ) ? $annotation['note'] : null, 'warnings' => $warnings,
			);
			$case_keys[ $key ] = true;
		}
	}
	// A stale annotation is always a bug: it means the inventory is describing a test that no
	// longer exists under that name, which is exactly the kind of quiet decay this tool exists
	// to prevent. Fail loudly rather than silently dropping it.
	$stale_hint = "\nThe test was renamed or removed. Delete or update this entry in\n"
		. OUTPUT_DIR . "/annotations.json, then re-run with --write.\n";
	foreach ( array_keys( $annotations['classes'] ) as $key ) {
			if ( ! isset( $class_names[ $key ] ) ) { fail( 'Stale class annotation (no such class was scanned): ' . $key . $stale_hint ); }
	}
	foreach ( array_keys( $annotations['cases'] ) as $key ) {
			if ( ! isset( $case_keys[ $key ] ) ) { fail( 'Stale case annotation (no such test case was scanned): ' . $key . $stale_hint ); }
	}
	usort( $cases, function ( $a, $b ) { return strcmp( $a['key'], $b['key'] ); } );
	$summary = array( 'cases' => count( $cases ), 'v1_only' => 0, 'unresolved' => 0, 'by_lane' => array( 'v1' => 0, 'v2' => 0, 'wc3' => 0, 'wp2' => 0, 'unresolved' => 0, 'unit' => 0 ),
		'warnings' => array( 'self-installed-hook' => 0, 'asserted-stubbed-response' => 0 ) );
	foreach ( $cases as $case ) {
		$summary['v1_only']    += $case['v1_only'] ? 1 : 0;
		$summary['unresolved'] += $case['unresolved'] ? 1 : 0;
		$patterns = array();
		foreach ( $case['warnings'] as $warning ) { $patterns[ $warning['pattern'] ] = true; }
		foreach ( array_keys( $patterns ) as $pattern ) { $summary['warnings'][ $pattern ]++; }
		if ( empty( $case['lanes'] ) ) {
			$summary['by_lane']['unit']++;
		} else {
			foreach ( $case['lanes'] as $lane ) { $summary['by_lane'][ $lane ]++; }
		}
	}
	return array( 'generated_by' => 'scripts/lane-coverage.php', 'summary' => $summary, 'cases' => $cases );
}
function inventory_json( $inventory ) {
	$json = json_encode( $inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false === $json ) { fail( 'Could not encode inventory JSON: ' . json_last_error_msg() ); }
	return $json . "\n";
}
function markdown_text( $text ) { return str_replace( array( '|', "\r", "\n" ), array( '\\|', '', '<br>' ), (string) $text ); }
function markdown_table( $cases ) {
	usort( $cases, function ( $a, $b ) {
		$result = strcmp( $a['behavior'], $b['behavior'] );
		return 0 === $result ? strcmp( $a['key'], $b['key'] ) : $result;
	} );
	$output = "| Behavior | File:case | Lane | Verdict | Note |\n|---|---|---|---|---|\n";
	foreach ( $cases as $case ) {
		$lane = empty( $case['lanes'] ) ? 'unit' : implode( ', ', $case['lanes'] );
		$note = null === $case['note'] ? '—' : $case['note'];
		$output .= '| ' . markdown_text( $case['behavior'] ) . ' | ' . markdown_text( $case['file'] . ':' . $case['method'] )
			. ' | ' . $lane . ' | ' . markdown_text( $case['verdict'] ) . ' | ' . markdown_text( $note ) . " |\n";
	}
	return $output;
}
function warning_rows( $inventory ) {
	$rows = array();
	foreach ( $inventory['cases'] as $case ) {
		foreach ( $case['warnings'] as $warning ) { $rows[] = array( 'warning' => $warning, 'case' => $case ); }
	}
	usort( $rows, function ( $a, $b ) {
		foreach ( array( 'pattern', 'file' ) as $field ) {
			$result = strcmp( $a['warning'][ $field ], $b['warning'][ $field ] );
			if ( 0 !== $result ) { return $result; }
		}
		$result = $a['warning']['line'] - $b['warning']['line'];
		return 0 === $result ? strcmp( $a['case']['key'], $b['case']['key'] ) : $result;
	} );
	return $rows;
}
function warnings_markdown( $inventory ) {
	$rows = warning_rows( $inventory );
	$output = "## Blind-test warnings (advisory — never fails CI)\n\nThese tests may be structurally incapable of failing because they install or stub the condition they assert.\n\n";
	if ( empty( $rows ) ) { return $output . "_None detected._\n"; }
	$output .= "| Pattern | File:line | Behavior | File:case |\n|---|---|---|---|\n";
	foreach ( $rows as $row ) {
		$warning = $row['warning'];
		$case    = $row['case'];
		$output .= '| ' . $warning['pattern'] . ' | ' . markdown_text( $warning['file'] . ':' . $warning['line'] )
			. ' | ' . markdown_text( $case['behavior'] ) . ' | ' . markdown_text( $case['file'] . ':' . $case['method'] ) . " |\n";
	}
	return $output;
}
function inventory_markdown( $inventory ) {
	$summary = $inventory['summary'];
	$output  = "<!-- Generated by scripts/lane-coverage.php. Regenerate with: php scripts/lane-coverage.php --write -->\n\n";
	$output .= "# PHPUnit REST lane coverage\n\n";
	$output .= "A test counts as coverage for current behaviour only if it exercises the lane the app\n"
		. "actually calls. v1-route tests are legacy pins and do not count — see README.md here.\n\n";
	$output .= "- Cases: {$summary['cases']}\n- v1-only cases: {$summary['v1_only']}\n"
		. "- Unresolved-route cases (not proven current, not claimed v1): {$summary['unresolved']}\n";
	$output .= '- By lane (overlapping; a case touching two lanes is counted twice): v1 ' . $summary['by_lane']['v1']
		. ', v2 ' . $summary['by_lane']['v2'] . ', wc3 ' . $summary['by_lane']['wc3']
		. ', wp2 ' . $summary['by_lane']['wp2'] . ', unresolved ' . $summary['by_lane']['unresolved']
		. ', pure unit ' . $summary['by_lane']['unit'] . "\n";
	$output .= '- Blind-test warnings (advisory): self-installed-hook ' . $summary['warnings']['self-installed-hook']
		. ', asserted-stubbed-response ' . $summary['warnings']['asserted-stubbed-response'] . "\n\n";
	$v1_only     = array_values( array_filter( $inventory['cases'], function ( $case ) { return $case['v1_only']; } ) );
	$unresolved  = array_values( array_filter( $inventory['cases'], function ( $case ) { return $case['unresolved']; } ) );
	$other       = array_values( array_filter( $inventory['cases'], function ( $case ) { return ! $case['v1_only'] && ! $case['unresolved']; } ) );
	$output .= "## Behaviours whose only coverage is a v1 route\n\n" . markdown_table( $v1_only );
	$output .= "\n## Cases whose dispatched route could not be resolved\n\n"
		. "Their route is assembled at runtime, so the scanner cannot show they exercise a current\n"
		. "lane. They are NOT claimed to be v1 — only unproven — and the CI ratchet guards them\n"
		. "alongside the v1-only list so a new test cannot slip past by building its route from\n"
		. "variables.\n\n" . markdown_table( $unresolved );
	$output .= "\n" . warnings_markdown( $inventory );
	$output .= "\n## All other behaviours\n\n" . markdown_table( $other );
	return $output;
}
function diff_hint( $path, $existing, $generated ) {
	$old_lines = explode( "\n", (string) $existing );
	$new_lines = explode( "\n", $generated );
	$limit     = max( count( $old_lines ), count( $new_lines ) );
	for ( $i = 0; $i < $limit; $i++ ) {
		$old = isset( $old_lines[ $i ] ) ? $old_lines[ $i ] : '<missing>';
		$new = isset( $new_lines[ $i ] ) ? $new_lines[ $i ] : '<missing>';
		if ( $old !== $new ) {
			return "--- $path\n+++ generated/$path\n@@ line " . ( $i + 1 ) . " @@\n-$old\n+$new\n";
		}
	}
	return '';
}
/**
 * The ratchet. Compares the set of v1-only case keys against a baseline inventory produced
 * from another git ref, and fails when the set has grown.
 *
 * Sets, not counts: a PR that ports one behaviour to v2 and simultaneously adds a new v1-only
 * test would leave the count unchanged while making things worse. Comparing keys catches that.
 *
 * Note that verdicts are irrelevant here by construction — only `v1_only` is read, and that
 * field is derived purely from dispatched lanes. No annotation can quiet this check.
 */
function compare_to_baseline( $inventory, $baseline_path ) {
	$baseline = json_decode( file_get_contents( $baseline_path ), true );
	if ( ! is_array( $baseline ) || ! isset( $baseline['cases'] ) || ! is_array( $baseline['cases'] ) ) {
		fail( 'Baseline JSON is not a lane-coverage inventory: ' . $baseline_path );
	}
	$base_keys = array();
	foreach ( $baseline['cases'] as $case ) {
		if ( ! empty( $case['v1_only'] ) || ! empty( $case['unresolved'] ) ) { $base_keys[ $case['key'] ] = true; }
	}
	$head_keys = array();
	foreach ( $inventory['cases'] as $case ) {
		if ( $case['v1_only'] || $case['unresolved'] ) { $head_keys[ $case['key'] ] = true; }
	}
	$added   = array_keys( array_diff_key( $head_keys, $base_keys ) );
	$removed = array_keys( array_diff_key( $base_keys, $head_keys ) );
	sort( $added, SORT_STRING );
	sort( $removed, SORT_STRING );

	echo 'Cases not proven to cover current behaviour (v1-only + unresolved route): '
		. count( $base_keys ) . ' (baseline) -> ' . count( $head_keys ) . " (this branch)\n";
	echo '  of which provably v1-only on this branch: ' . $inventory['summary']['v1_only']
		. '; unresolved route: ' . $inventory['summary']['unresolved'] . "\n";
	foreach ( $removed as $key ) { echo '  ported or retired: ' . $key . "\n"; }
	if ( empty( $added ) ) {
		echo "No new v1-only coverage.\n";
		exit( 0 );
	}
	fwrite( STDERR, "\nNew cases with no proven coverage of current behaviour:\n" );
	foreach ( $added as $key ) {
		fwrite( STDERR, '  ' . $key . "\n" );
		echo '::error::New case without current-lane coverage: ' . $key . "\n";
	}
	fwrite(
		STDERR,
		"\nA wcpos/v1 test does not count as coverage for current behaviour, and a route\n"
		. "assembled at runtime cannot be shown to — see tests/lane-coverage/README.md.\n"
		. "Dispatch the new case to wcpos/v2 (or wc/v3) using a literal route, or, if it is a\n"
		. "deliberate legacy pin, add current-lane coverage alongside it.\n"
	);
	exit( 1 );
}

list( $mode, $root, $root_arg, $compare_path ) = parse_cli( array_slice( $argv, 1 ) );
$inventory = build_inventory( scan_classes( $root ), read_annotations( $root ) );
$json      = inventory_json( $inventory );
if ( 'json' === $mode ) {
	echo $json; exit( 0 );
}
if ( 'compare' === $mode ) {
	compare_to_baseline( $inventory, $compare_path );
}
if ( 'warnings' === $mode ) {
	foreach ( warning_rows( $inventory ) as $row ) {
		$warning = $row['warning'];
		$case    = $row['case'];
		echo $warning['file'] . ':' . $warning['line'] . ': ' . $warning['pattern'] . ': ' . $case['class'] . '::' . $case['method'] . "\n";
	}
	exit( 0 );
}
$markdown = inventory_markdown( $inventory );
$outputs  = array( OUTPUT_DIR . '/inventory.json' => $json, OUTPUT_DIR . '/inventory.md' => $markdown );
if ( 'write' === $mode ) {
	$directory = $root . '/' . OUTPUT_DIR;
	if ( ! is_dir( $directory ) && ! mkdir( $directory, 0777, true ) && ! is_dir( $directory ) ) {
		fail( 'Could not create output directory: ' . OUTPUT_DIR );
	}
	foreach ( $outputs as $path => $contents ) {
		if ( false === file_put_contents( $root . '/' . $path, $contents ) ) { fail( 'Could not write: ' . $path ); }
	}
	exit( 0 );
}
$different = false;
foreach ( $outputs as $path => $contents ) {
	$existing = is_file( $root . '/' . $path ) ? file_get_contents( $root . '/' . $path ) : false;
	if ( $existing !== $contents ) {
		$different = true;
		fwrite( STDERR, diff_hint( $path, $existing, $contents ) );
	}
}
if ( $different ) {
	$command = 'php scripts/lane-coverage.php --write';
	if ( null !== $root_arg ) { $command .= ' --root=' . escapeshellarg( $root_arg ); }
	fwrite( STDERR, "Lane coverage inventory is stale. Regenerate with:\n$command\n" );
	exit( 1 );
}

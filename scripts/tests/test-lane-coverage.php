#!/usr/bin/env php
<?php
$root     = sys_get_temp_dir() . '/wcpos-lane-coverage-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
$test_dir = $root . '/tests';

if ( ! mkdir( $test_dir, 0777, true ) && ! is_dir( $test_dir ) ) {
	fwrite( STDERR, "Could not create fixture directory.\n" );
	exit( 1 );
}

register_shutdown_function(
	function () use ( $root ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $path ) {
			$path->isDir() ? rmdir( $path->getPathname() ) : unlink( $path->getPathname() );
		}
		rmdir( $root );
	}
);

$fixture = <<<'PHP'
<?php
class Test_Lane_Coverage_Fixture {
	public function test_dynamic_v1_concatenation() {
		$this->wp_rest_get_request( '/wcpos/' . 'v1/products' );
	}

	public function test_dynamic_v2_concatenation() {
		$this->wp_rest_get_request( '/wcpos/' . 'v2/products' );
	}

	public function test_unresolved_route_variable() {
		$this->wp_rest_get_request( $route );
	}

	public function test_dynamic_rest_request_concatenation() {
		new \WP_REST_Request( 'GET', '/wcpos/' . 'v1/products' );
	}

	public function test_unresolved_rest_request_variable() {
		new \WP_REST_Request( 'GET', $route );
	}

	public function test_live_v1_collection() {
		$this->wp_rest_get_request( '/wcpos/v1/products/variations' );
	}

	public function test_live_v1_collection_query() {
		$this->wp_rest_get_request( '/wcpos/v1/products/variations?context=edit' );
	}

	public function test_live_v1_collection_concatenation() {
		$this->wp_rest_get_request( '/wcpos/v1/products/' . 'variations' );
	}

	public function test_v1_collection_descendant() {
		$this->wp_rest_get_request( '/wcpos/v1/products/variations/123' );
	}

	public function test_v1_collection_prefix_collision() {
		$this->wp_rest_get_request( '/wcpos/v1/products/variations-legacy' );
	}

	public function test_live_v1_subtree_root() {
		$this->wp_rest_get_request( '/wcpos/v1/print-jobs' );
	}

	public function test_live_v1_subtree_descendant() {
		$this->wp_rest_get_request( '/wcpos/v1/print-jobs/queue' );
	}

	public function test_live_v1_subtree_dynamic_descendant() {
		$this->wp_rest_get_request( '/wcpos/v1/print-jobs/' . $id . '/reprint' );
	}

	public function test_live_v1_subtree_prefix_collision() {
		$this->wp_rest_get_request( '/wcpos/v1/print-jobs-legacy' );
	}
}

class Test_Lane_Coverage_Helper_Fixture {
	private function request( $route ) {
		return new \WP_REST_Request( 'GET', $route );
	}

	public function test_unresolved_helper_route() {
		$this->request( $route );
	}
}
PHP;

file_put_contents( $test_dir . '/Test_Lane_Coverage_Fixture.php', $fixture );

// Lane precedence. A case is judged on the lanes it carries ITSELF when it has any,
// so one sibling dispatching a current lane cannot clear the rest of the class —
// that union was silently marking whole classes as ported. A case with no positive
// lane of its own still inherits the class's, including when its own signal is only
// `unresolved` (a route passed as a constant still comes FROM the class).
$precedence_fixture = <<<'PHP'
<?php
class Test_Lane_Precedence_Fixture {
	private const CURRENT_LANE_ROUTE = '/wcpos/v2/products';

	private function class_scope_helper() {
		$this->wp_rest_get_request( '/wcpos/v1/products' );
	}

	public function test_precedence_sibling_dispatches_current_lane() {
		$this->wp_rest_get_request( '/wcpos/v2/products' );
	}

	public function test_precedence_own_v1_survives_a_current_lane_sibling() {
		$this->wp_rest_get_request( '/wcpos/v1/products/42' );
	}

	public function test_precedence_no_own_lane_inherits_the_class() {
		$this->assertTrue( true );
	}

	public function test_precedence_unresolved_own_signal_inherits_the_class() {
		$this->wp_rest_get_request( self::CURRENT_LANE_ROUTE );
	}
}
PHP;
file_put_contents( $test_dir . '/Test_Lane_Precedence_Fixture.php', $precedence_fixture );

$scanner = dirname( __DIR__ ) . '/lane-coverage.php';
$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $scanner ) . ' --json --root=' . escapeshellarg( $root );
exec( $command, $output, $status );
if ( 0 !== $status ) {
	fwrite( STDERR, "Scanner command failed with exit {$status}.\n" );
	exit( 1 );
}

$inventory = json_decode( implode( "\n", $output ), true );
$cases     = array();
foreach ( $inventory['cases'] as $case ) {
	$cases[ $case['method'] ] = $case;
}

// array( lanes, v1_only, unresolved ).
//
// A route the scanner cannot resolve is reported as `unresolved`, never as `v1`: claiming a
// runtime-assembled route is v1 would put a false statement in the inventory (five such cases
// in the free repo are, by name, v2 tests). It is still guarded by the CI ratchet, which
// counts v1-only and unresolved together — so the conservatism is preserved without the lie.
$expected = array(
	// Lane precedence. `lanes` stays the class/method UNION throughout — it is
	// provenance, and every row below shows the same union — while the VERDICT is
	// reached on the lanes the case carries itself. The two must be read together.
	//
	// The case that matters: this class dispatches v2 in a sibling and names a v2
	// route in a class constant, yet a method whose own route is v1 is still
	// reported v1-only. Before this, one sibling cleared the whole class.
	'test_precedence_own_v1_survives_a_current_lane_sibling' => array( array( 'v1', 'v2' ), true, false ),
	// The sibling itself is judged on its own v2 and is not reported.
	'test_precedence_sibling_dispatches_current_lane' => array( array( 'v1', 'v2' ), false, false ),
	// No own lane at all, so the class genuinely speaks for it — the shared
	// base-route pattern, which must keep working.
	'test_precedence_no_own_lane_inherits_the_class' => array( array( 'v1', 'v2' ), false, false ),
	// Own signal is ONLY `unresolved`, which is the absence of a route rather than a
	// lane of its own, so the class's current lane still applies. Without this a test
	// passing `self::CURRENT_LANE_ROUTE` would lose the very lane that constant
	// supplies — it flipped all five Test_Cache_Headers cases to unresolved.
	'test_precedence_unresolved_own_signal_inherits_the_class' => array( array( 'unresolved', 'v1', 'v2' ), false, false ),
	'test_dynamic_v1_concatenation'   => array( array( 'v1' ), true, false ),
	'test_dynamic_v2_concatenation'   => array( array( 'v2' ), false, false ),
	'test_unresolved_route_variable'  => array( array( 'unresolved' ), false, true ),
	'test_dynamic_rest_request_concatenation' => array( array( 'v1' ), true, false ),
	'test_unresolved_rest_request_variable' => array( array( 'unresolved' ), false, true ),
	'test_live_v1_collection'         => array( array( 'v1' ), false, false ),
	'test_live_v1_collection_query'   => array( array( 'v1' ), false, false ),
	'test_live_v1_collection_concatenation' => array( array( 'v1' ), false, false ),
	'test_v1_collection_descendant'   => array( array( 'v1' ), true, false ),
	'test_v1_collection_prefix_collision' => array( array( 'v1' ), true, false ),
	'test_live_v1_subtree_root'        => array( array( 'v1' ), false, false ),
	'test_live_v1_subtree_descendant'  => array( array( 'v1' ), false, false ),
	'test_live_v1_subtree_dynamic_descendant' => array( array( 'v1' ), false, false ),
	'test_live_v1_subtree_prefix_collision' => array( array( 'v1' ), true, false ),
	'test_unresolved_helper_route'     => array( array( 'unresolved' ), false, true ),
);

foreach ( $expected as $method => $assertion ) {
	$actual = isset( $cases[ $method ] )
		? array( $cases[ $method ]['lanes'], $cases[ $method ]['v1_only'], $cases[ $method ]['unresolved'] )
		: null;
	if ( $assertion !== $actual ) {
		fwrite( STDERR, $method . ': expected ' . json_encode( $assertion ) . ', got ' . json_encode( $actual ) . "\n" );
		exit( 1 );
	}
}

echo count( $expected ) . " lane-coverage scanner regression tests passed.\n";

// ---------------------------------------------------------------------------
// Ratchet (--compare) regression tests.
//
// The ratchet is the half of this tool that can actually block a merge, so it needs
// its own pins — an instrument nobody can make fail gates nothing. Renaming a v1-only
// class re-labels every one of its keys at once, which reads to a key-based diff as a
// wholesale retire-and-add. The `renames` annotation cancels that, and these cases pin
// BOTH directions: that a declared rename passes, and that the gate still bites on
// everything a rename is not.
// ---------------------------------------------------------------------------

$ratchet_root = sys_get_temp_dir() . '/wcpos-lane-ratchet-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
register_shutdown_function(
	function () use ( $ratchet_root ) {
		if ( ! is_dir( $ratchet_root ) ) { return; }
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $ratchet_root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $path ) {
			$path->isDir() ? rmdir( $path->getPathname() ) : unlink( $path->getPathname() );
		}
		rmdir( $ratchet_root );
	}
);

/**
 * Write a single-class v1-only fixture tree, plus annotations, and return its root.
 */
$write_tree = function ( $name, $class, array $methods, array $annotations ) use ( $ratchet_root ) {
	$root = $ratchet_root . '/' . $name;
	mkdir( $root . '/tests/lane-coverage', 0777, true );

	$body = "<?php\nclass {$class} {\n";
	foreach ( $methods as $method => $route ) {
		$body .= "\tpublic function {$method}() {\n\t\t\$this->wp_rest_get_request( '{$route}' );\n\t}\n";
	}
	$body .= "}\n";
	file_put_contents( $root . '/tests/' . $class . '.php', $body );
	file_put_contents(
		$root . '/tests/lane-coverage/annotations.json',
		json_encode( $annotations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
	);

	return $root;
};

$run = function ( array $arguments ) use ( $scanner ) {
	$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $scanner );
	foreach ( $arguments as $argument ) {
		$command .= ' ' . escapeshellarg( $argument );
	}
	$output = array();
	$status = 0;
	exec( $command . ' 2>&1', $output, $status );

	return array( $status, implode( "\n", $output ) );
};

$ratchet_failures = 0;
$ratchet_checks   = 0;
$check            = function ( $label, $expected_status, $actual_status, $output, $expected_text = null ) use ( &$ratchet_failures, &$ratchet_checks ) {
	$ratchet_checks++;
	$ok = $expected_status === $actual_status
		&& ( null === $expected_text || false !== strpos( $output, $expected_text ) );
	if ( ! $ok ) {
		$ratchet_failures++;
		fwrite( STDERR, "ratchet: {$label}: expected exit {$expected_status}" );
		fwrite( STDERR, null === $expected_text ? '' : " containing \"{$expected_text}\"" );
		fwrite( STDERR, ", got exit {$actual_status}:\n{$output}\n\n" );
	}
};

$legacy = array( 'test_alpha' => '/wcpos/v1/products', 'test_beta' => '/wcpos/v1/products/42' );

// The baseline: one v1-only class under its original name.
$base_root = $write_tree( 'base', 'Test_Ratchet_Old_Name', $legacy, array( 'classes' => array(), 'cases' => array() ) );
$base_json = $ratchet_root . '/base.json';
list( $status, $output ) = $run( array( '--json', '--root=' . $base_root ) );
$check( 'baseline scan succeeds', 0, $status, $output );
file_put_contents( $base_json, $output );

// 1. Pure rename, declared. No coverage changed, so the gate must not fire.
$renamed = $write_tree(
	'renamed',
	'Test_Ratchet_New_Name',
	$legacy,
	array( 'classes' => array(), 'cases' => array(), 'renames' => array( 'Test_Ratchet_Old_Name' => 'Test_Ratchet_New_Name' ) )
);
list( $status, $output ) = $run( array( '--compare=' . $base_json, '--root=' . $renamed ) );
$check( 'declared rename passes the ratchet', 0, $status, $output, 'No new v1-only coverage.' );
$check( 'declared rename is reported, not silent', 0, $status, $output, 'Relabelled 2 baseline case(s)' );

// 2. The SAME rename, undeclared. Proves the annotation is what cancels it, and that a
//    key-based diff really does read a rename as new coverage.
$undeclared = $write_tree( 'undeclared', 'Test_Ratchet_New_Name', $legacy, array( 'classes' => array(), 'cases' => array() ) );
list( $status, $output ) = $run( array( '--compare=' . $base_json, '--root=' . $undeclared ) );
$check( 'undeclared rename still fails', 1, $status, $output, 'New case without current-lane coverage' );

// 3. A declared rename must not smuggle in genuinely new v1-only coverage alongside it.
$smuggled = $write_tree(
	'smuggled',
	'Test_Ratchet_New_Name',
	$legacy + array( 'test_gamma' => '/wcpos/v1/orders' ),
	array( 'classes' => array(), 'cases' => array(), 'renames' => array( 'Test_Ratchet_Old_Name' => 'Test_Ratchet_New_Name' ) )
);
list( $status, $output ) = $run( array( '--compare=' . $base_json, '--root=' . $smuggled ) );
$check( 'a rename cannot smuggle new v1-only coverage', 1, $status, $output, 'Test_Ratchet_New_Name::test_gamma' );

// 4. A rename entry pointing at a class that was never scanned is a typo doing nothing.
//    Exit 2 rather than 1: a malformed annotation is a scan failure (fail()), not a
//    ratchet verdict, and it fires on every mode including --json.
$bad_target = $write_tree(
	'bad-target',
	'Test_Ratchet_New_Name',
	$legacy,
	array( 'classes' => array(), 'cases' => array(), 'renames' => array( 'Test_Ratchet_Old_Name' => 'Test_Ratchet_Typo' ) )
);
list( $status, $output ) = $run( array( '--json', '--root=' . $bad_target ) );
$check( 'rename target must exist', 2, $status, $output, 'Rename target was not scanned' );

// 5. A rename whose source is still live is not a rename, and cancelling its keys would
//    hide real v1-only coverage.
$live_source = $write_tree(
	'live-source',
	'Test_Ratchet_Old_Name',
	$legacy,
	array( 'classes' => array(), 'cases' => array(), 'renames' => array( 'Test_Ratchet_Old_Name' => 'Test_Ratchet_Old_Name' ) )
);
list( $status, $output ) = $run( array( '--json', '--root=' . $live_source ) );
$check( 'rename source must be gone', 2, $status, $output, 'Rename source is still a live class' );

// 6. A JSON list decodes to integer keys, which would read as a correctly-absent source
//    (no class is named "0") and silently do nothing. Reject the shape instead.
$list_shaped = $write_tree(
	'list-shaped',
	'Test_Ratchet_New_Name',
	$legacy,
	array( 'classes' => array(), 'cases' => array(), 'renames' => array( 'Test_Ratchet_New_Name' ) )
);
list( $status, $output ) = $run( array( '--json', '--root=' . $list_shaped ) );
$check( 'renames must be an object, not a list', 2, $status, $output, 'must be an object, not a list' );

if ( $ratchet_failures > 0 ) {
	fwrite( STDERR, $ratchet_failures . " lane-coverage ratchet regression test(s) failed.\n" );
	exit( 1 );
}

echo $ratchet_checks . " lane-coverage ratchet regression tests passed.\n";

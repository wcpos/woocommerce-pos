<?php

$vendorDir = __DIR__ . '/vendor_prefixed';
$autoloadFile = $vendorDir . '/autoload.php';

$files = array();

$directory = new RecursiveDirectoryIterator( $vendorDir, RecursiveDirectoryIterator::SKIP_DOTS );
$iterator = new RecursiveIteratorIterator( $directory, RecursiveIteratorIterator::SELF_FIRST );

// Function to strip comments from PHP code
function strip_comments( $source ) {
	// Regular expressions for removing single-line and multi-line comments
	$singleLineCommentPattern = '/\/\/.*$/m';
	$multiLineCommentPattern = '/\/\*.*?\*\//s';
	// Remove the comments from the source code
	$source = preg_replace( $singleLineCommentPattern, '', $source );
	$source = preg_replace( $multiLineCommentPattern, '', $source );
	return $source;
}

foreach ( $iterator as $file ) {
	if ( $file->isFile() && $file->getExtension() === 'php' ) {
		$relativePath = str_replace( $vendorDir . '/', '', $file->getPathname() );

		// Read the namespace and class name from the file
		$fileContent = file_get_contents( $file->getPathname() );

		// Strip comments from the file content
		$fileContent = strip_comments( $fileContent );

		$namespace = '';
		$class = '';

		if ( preg_match( '/namespace\s+([^;\s]+)\s*;/', $fileContent, $matches ) ) {
			$namespace = $matches[1];
		}
		if ( preg_match( '/\b(class|interface|trait)\s+([^\s{]+)/', $fileContent, $classMatches ) ) {
			$class = $classMatches[2];
		}

		// Only add valid class mappings
		if ( $namespace && $class ) {
			$fullClassName = $namespace . '\\' . $class;
			echo 'Found class: ' . $fullClassName . PHP_EOL;
			$files[ $fullClassName ] = './' . $relativePath;
		}
	}
}

echo 'Number of entries in files array: ' . count( $files ) . PHP_EOL;

$autoloadContent = "<?php\n\n";
$autoloadContent .= "// autoload.php @generated by Composer\n\n";
$autoloadContent .= "\$classMap = [\n";

foreach ( $files as $className => $path ) {
	$autoloadContent .= "    '$className' => '$path',\n";
}

$autoloadContent .= "];\n\n";
$autoloadContent .= "spl_autoload_register(function (\$class) use (\$classMap) {\n";
$autoloadContent .= "    if (isset(\$classMap[\$class])) {\n";
$autoloadContent .= "        require __DIR__ . '/' . \$classMap[\$class];\n";
$autoloadContent .= "    }\n";
$autoloadContent .= "});\n";

file_put_contents( $autoloadFile, $autoloadContent );

echo "Autoload file generated at $autoloadFile\n";

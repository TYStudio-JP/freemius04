<?php
/**
 * Development aid: prints the syntax tree of a PHP file with the source text
 * each node covers, so that rules and fixes work from real positions.
 *
 *   php bin/dump-ast.php file.php [7.4]
 *
 * The second argument selects the grammar: the newest one by default, or the
 * PHP 7.4 grammar, which still accepts syntax that PHP 8.0 removed.
 *
 * @package UpgradeWarden
 */

if ( 'cli' !== PHP_SAPI || $argc < 2 ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/src/autoload.php';

use TYStudio\UpgradeWarden\Vendor\PhpParser\ErrorHandler\Collecting;
use TYStudio\UpgradeWarden\Vendor\PhpParser\Node;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeTraverser;
use TYStudio\UpgradeWarden\Vendor\PhpParser\NodeVisitorAbstract;
use TYStudio\UpgradeWarden\Vendor\PhpParser\ParserFactory;
use TYStudio\UpgradeWarden\Vendor\PhpParser\PhpVersion;

$code = file_get_contents( $argv[1] );

// A fixture keeps its program in the CODE section.
if ( preg_match( '/^--CODE--\n(.*?)(?=^--[A-Z]+--$|\z)/ms', $code, $match ) ) {
	$code = $match[1];
}

$factory = new ParserFactory();
$parser  = isset( $argv[2] ) ? $factory->createForVersion( PhpVersion::fromString( $argv[2] ) ) : $factory->createForNewestSupportedVersion();
$errors  = new Collecting();
$ast     = $parser->parse( $code, $errors );

foreach ( $errors->getErrors() as $error ) {
	echo 'ERROR: ', $error->getMessage(), "\n";
}

if ( null === $ast ) {
	exit( 1 );
}

$traverser = new NodeTraverser();

$traverser->addVisitor(
	new class( $code ) extends NodeVisitorAbstract {
		private $code;
		private $depth = 0;

		public function __construct( $code ) {
			$this->code = $code;
		}

		public function enterNode( Node $node ) {
			$start = $node->getStartFilePos();
			$end   = $node->getEndFilePos();
			$text  = preg_replace( '/\s+/', ' ', substr( $this->code, $start, $end - $start + 1 ) );
			$extra = $node->hasAttribute( 'kind' ) ? ' kind=' . $node->getAttribute( 'kind' ) : '';

			printf( "%s%s [%d-%d]%s  %s\n", str_repeat( '  ', $this->depth ), $node->getType(), $start, $end, $extra, substr( $text, 0, 70 ) );
			++$this->depth;

			return null;
		}

		public function leaveNode( Node $node ) {
			--$this->depth;

			return null;
		}
	}
);
$traverser->traverse( $ast );

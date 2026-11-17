<?php
/**
 * PHP 8.4 backward incompatible changes, MBString to XML (tranche
 * 8.4-bic-rest): code that looks like a reported use and is fine, and so must
 * stay unreported.
 *
 * Only the rule ids of this tranche are looked at, so that a rule somebody
 * else adds for the same code does not make these tests fail.
 *
 * @package UpgradeWarden
 */

$tests = array();

$mine = static function ( $code ) {
	$ids = array(
		'argument.pcre_brace_quantifier',
		'argument.strcspn_empty_characters',
	);

	$found = array();

	foreach ( upgw_scanner()->scan( "<?php\n" . $code )->findings as $finding ) {
		if ( in_array( $finding->rule, $ids, true ) ) {
			$found[] = $finding->rule;
		}
	}

	return $found;
};

$clean = static function ( $code, $message ) use ( $mine ) {
	upgw_same( array(), $mine( $code ), $message . "\n      code: " . $code );
};

$tests['8.4-bic-rest: braces that were already a quantifier, or are no quantifier at all, are not reported'] = static function () use ( $clean ) {
	$clean( "preg_match( '/a{3}/', \$s );", 'Exact count.' );
	$clean( "preg_match( '/a{1,}/', \$s );", 'Minimum only.' );
	$clean( "preg_match( '/a{1,3}/', \$s );", 'Minimum and maximum.' );
	$clean( "preg_match( '/a{1,3}?/', \$s );", 'Lazy quantifier.' );
	$clean( "preg_match( '/a{,}/', \$s );", 'Comma alone: text before and after.' );
	$clean( "preg_match( '/a{ }/', \$s );", 'Space alone: text before and after.' );
	$clean( "preg_match( '/a{ , }/', \$s );", 'Comma and spaces: text before and after.' );
	$clean( "preg_match( '/a{,3,}/', \$s );", 'Two commas: text before and after.' );
	$clean( "preg_match( \"/a{\\n2}/\", \$s );", 'A newline inside the braces: text before and after.' );
	$clean( "preg_match( '/\\s*{\\s*/', \$css );", 'CSS block opener.' );
	$clean( "preg_match( '/{(\\w+)}/', \$template );", 'Template placeholder.' );
	$clean( "preg_match( '/{{ name }}/', \$template );", 'Mustache placeholder with a name.' );
};

$tests['8.4-bic-rest: braces that are escaped, quoted or inside a class are not reported'] = static function () use ( $clean ) {
	$clean( "preg_match( '/a\\{,3}/', \$s );", 'Escaped brace.' );
	$clean( "preg_match( '/a\\\\{,3}/', \$s );", 'Escaped brace in a double-escaped PHP string.' );
	$clean( "preg_match( '/[{,3}]+/', \$s );", 'Character class.' );
	$clean( "preg_match( '/[]{,3}]+/', \$s );", 'Character class starting with ].' );
	$clean( "preg_match( '/\\Q{,3}\\E/', \$s );", 'Quoted with \\Q...\\E.' );
	$clean( "preg_match( '/(?#{,3})a/', \$s );", 'Inline comment.' );
	$clean( "preg_match( '/\\x{61}{2}/', \$s );", 'Hex escape followed by an old quantifier.' );
	$clean( "preg_match( '/\\p{L}{1,3}/u', \$s );", 'Property escape followed by an old quantifier.' );
	$clean( "preg_match( '/a{,3}  # {,3} /x', \$s );", 'Extended mode is not judged.' );
	$clean( "preg_match( '/(?x)a{,3}/', \$s );", 'Inline extended mode is not judged.' );
	$clean( "preg_match( '{a{,3}}', \$s );", 'Braces as delimiters are not judged.' );
};

$tests['8.4-bic-rest: patterns that are not literal or not given to preg functions are not reported'] = static function () use ( $clean, $mine ) {
	$clean( "preg_match( \$pattern, 'a{,3}' );", 'Pattern in a variable, braces in the subject.' );
	$clean( "preg_match( \"/a{\$min,3}/\", \$s );", 'Interpolated pattern.' );
	$clean( "preg_replace( '/a/', '{,3}', \$s );", 'Braces in the replacement.' );
	$clean( "str_replace( '{,3}', '', \$s );", 'Not a preg function.' );
	$clean( "\$re->preg_match( '/a{,3}/', \$s );", 'A method called preg_match.' );
	$clean( "App\\Regex\\preg_match( '/a{,3}/', \$s );", 'A namespaced function called preg_match.' );
	$clean( "preg_replace_callback_array( array( '/a{3}/' => 'f' ), \$s );", 'Callback array with an old quantifier.' );

	upgw_same( array( 'argument.pcre_brace_quantifier' ), $mine( "preg_split( '/,{ 2 }/', \$s );" ), 'Spaces inside the braces are reported.' );
	upgw_same( array( 'argument.pcre_brace_quantifier' ), $mine( "preg_replace( array( '/a/', '/b{,2}/' ), '', \$s );" ), 'Array of patterns.' );
	upgw_same( array( 'argument.pcre_brace_quantifier' ), $mine( "preg_match( '#^\\d{1, 3}$#', \$s );" ), 'Other delimiter, space after the comma.' );
};

$tests['8.4-bic-rest: obvious variants of the call are reported'] = static function () use ( $mine ) {
	$pcre = array( 'argument.pcre_brace_quantifier' );
	$cspn = array( 'argument.strcspn_empty_characters' );

	upgw_same( $pcre, $mine( "namespace App;\npreg_match( '/a{,3}/', \$s );" ), 'Unqualified call in a namespace falls back to the global function.' );
	upgw_same( $pcre, $mine( "PREG_MATCH( '/a{,3}/', \$s );" ), 'Function names are case-insensitive.' );
	upgw_same( $pcre, $mine( "\$f = static fn( \$s ) => \\preg_replace( pattern: '/a{ 2 }/', replacement: '', subject: \$s );" ), 'Arrow function, fully qualified, named arguments.' );
	upgw_same( $pcre, $mine( "preg_grep( <<<'RE'\n/c{1, 2}/\nRE, \$list );" ), 'Nowdoc pattern.' );
	upgw_same( $pcre, $mine( "preg_match( '/\\Qa\\E{,3}/', \$s );" ), 'Braces right after \\Q...\\E.' );
	upgw_same( $pcre, $mine( "preg_match( '/\\x{41}{,2}/', \$s );" ), 'Braces right after a \\x{..} escape.' );
	upgw_same( $cspn, $mine( "namespace App;\nfunction f( \$s ) { return STRCSPN( \$s, \"\" ); }" ), 'strcspn() in a namespaced function, upper case, double quotes.' );
	upgw_same( $cspn, $mine( "\\strcspn( \$s, '', 2 );" ), 'strcspn() with an offset.' );
};

$tests['8.4-bic-rest: strcspn() with characters, or with a literal string without NUL bytes, is not reported'] = static function () use ( $clean, $mine ) {
	$clean( "strcspn( \$s, 'abc' );", 'Characters given.' );
	$clean( "strcspn( \$s, \$chars );", 'Characters in a variable.' );
	$clean( "strcspn( 'abc', '' );", 'Literal string without a NUL byte.' );
	$clean( "strspn( \$s, '' );", 'strspn(), not strcspn().' );
	$clean( "strcspn( \$s, ' ' );", 'A space is a character.' );
	$clean( "\$o->strcspn( \$s, '' );", 'A method called strcspn.' );
	$clean( "\\App\\strcspn( \$s, '' );", 'A namespaced function called strcspn.' );

	upgw_same( array( 'argument.strcspn_empty_characters' ), $mine( "strcspn( characters: '', string: \$s );" ), 'Named arguments.' );
	upgw_same( array( 'argument.strcspn_empty_characters' ), $mine( "strcspn( \"a\\0b\", \"\" );" ), 'Literal string with a NUL byte.' );
};

return $tests;

<?php
/**
 * Configuration file for https://github.com/FriendsOfPHP/PHP-CS-Fixer
 * Install with composer: $ composer global require friendsofphp/php-cs-fixer
 * Usage (in this directory) :  ~/.composer/vendor/bin/php-cs-fixer fix .
 */

$finder = PhpCsFixer\Finder::create()
	->exclude('vendor')
	->in(__DIR__)
	->name('*.php')
	->ignoreDotFiles(true)
	->ignoreVCS(true);

$config = new PhpCsFixer\Config();
return $config
	->setIndent("\t")
	->setLineEnding("\r\n")
	->setRules([
//		'@PSR2' => true,

		// Array.
		'array_syntax' => ['syntax' => 'long'],

		// Basic.
		'braces' => [
			'allow_single_line_closure' => true,
			'position_after_anonymous_constructs' => 'same',
			'position_after_functions_and_oop_constructs' => 'same'
		],

		// Casing.
		// 'class_reference_name_casing' => true,
		'constant_case' => ['case' => 'lower'],

		// Cast Notation.
		'cast_spaces' => true,
		'lowercase_cast' => true,
		'no_short_bool_cast' => true,
		'short_scalar_cast' => true,

		// Class Notation.
		//'class_attributes_separation' => true,
		'class_definition' => true,
		'no_blank_lines_after_class_opening' => true,
		//'no_null_property_initialization' => true,
		'no_php4_constructor' => true,
		'ordered_class_elements' => true,
		'ordered_traits' => true,
		'self_accessor' => true,
		'single_class_element_per_statement' => true,
		'single_trait_insert_per_statement' => true,
		'visibility_required' => true,

		// Comment.
		//'multiline_comment_opening_closing' => true,
		'no_empty_comment' => true,
		'no_trailing_whitespace_in_comment' => true,
		// 'single_line_comment_spacing' => true,
		'single_line_comment_style' => true,

		// Control Structure
		// 'control_structure_braces' => true,
		'control_structure_continuation_position' => true,
		'elseif' => true,
		'include' => true,
		'no_alternative_syntax' => true,
		'no_superfluous_elseif' => true,
		'no_unneeded_control_parentheses' => true,
		'no_useless_else' => true,
		'switch_case_semicolon_to_colon' => true,
		'switch_case_space' => true,
		'switch_continue_to_break' => true,
		'trailing_comma_in_multiline' => true,
		'yoda_style' => true,

		// Function Notation
		'combine_nested_dirname' => true,
		'function_typehint_space' => true,
		'implode_call' => true,
		'lambda_not_used_import' => true,
		'native_function_invocation' => true,
		'no_spaces_after_function_name' => true,
		// 'no_trailing_comma_in_singleline_function_call' => true,
		'no_unreachable_default_argument_value' => true,
		'nullable_type_declaration_for_default_null_value' => true,
		'return_type_declaration' => true,
		//'single_line_throw' => true,
		'void_return' => true,

		// Import
		'fully_qualified_strict_types' => true,
		'global_namespace_import' => true,
		'no_leading_import_slash' => true,
		'no_unused_imports' => true,
		'ordered_imports' => true,
		'single_import_per_statement' => true,
		'single_line_after_imports' => true,

		// Language Construct
		//'combine_consecutive_issets' => true,
		//'combine_consecutive_unsets' => true,
		'function_to_constant' => true,

		// Namespace Notation
		'blank_line_after_namespace' => true,
		'clean_namespace' => true,
		'no_leading_namespace_whitespace' => true,
		'single_blank_line_before_namespace' => true,

		// Operator
		'concat_space' => ['spacing' => 'one'],
		'increment_style' => ['style' => 'post'],
		'logical_operators' => true,
		'new_with_braces' => true,
		'no_space_around_double_colon' => true,
		'not_operator_with_space' => true,
		'object_operator_without_whitespace' => true,
		'standardize_increment' => true,
		'standardize_not_equals' => true,
		'ternary_operator_spaces' => true,
		'ternary_to_null_coalescing' => true,
		'binary_operator_spaces' => ['default' => 'align_single_space'],

		// PHP Tag
		'echo_tag_syntax' => true,
		//'linebreak_after_opening_tag' => true,
		'no_closing_tag' => true,

		// PHPUnit
		'php_unit_fqcn_annotation' => true,
		'php_unit_method_casing' => ['case' => 'snake_case'],
		'php_unit_test_annotation' => true,
		'php_unit_test_class_requires_covers' => true,
		'php_unit_internal_class' => true,
		'php_unit_construct' => true,

		// PHPDoc
		'align_multiline_comment' => true,
		'general_phpdoc_annotation_remove' => true,
		'no_empty_phpdoc' => true,
		'phpdoc_add_missing_param_annotation' => true,
		'phpdoc_align' => true,
		'phpdoc_indent' => true,
		'phpdoc_line_span' => true,
		'phpdoc_no_access' => true,
		'phpdoc_no_alias_tag' => true,
		'phpdoc_no_package' => true,
		'phpdoc_order_by_value' => true,
		'phpdoc_order' => true,
		'phpdoc_return_self_reference' => true,
		'phpdoc_scalar' => true,
		'phpdoc_separation' => true,
		'phpdoc_summary' => true,
		'phpdoc_tag_casing' => true,
		'phpdoc_tag_type' => true,
		'phpdoc_to_comment' => true,
		'phpdoc_trim_consecutive_blank_line_separation' => true,
		'phpdoc_trim' => true,
		'phpdoc_types' => true,
		'phpdoc_types_order' => true,
		'phpdoc_var_annotation_correct_order' => true,
		'phpdoc_var_without_name' => true,

		// Return Notation.
		'no_useless_return' => true,
		'return_assignment' => true,

		// Semicolon.
		'multiline_whitespace_before_semicolons' => true,
		'no_singleline_whitespace_before_semicolons' => true,
		'semicolon_after_instruction' => true,
		'space_after_semicolon' => true,

		// Strict.
		//'declare_strict_types' => true,
		//'strict_comparison' => true,
		'strict_param' => true,

		// Whitespace.
		'array_indentation' => true,
		'blank_line_before_statement' => true,
		// 'blank_line_between_import_groups' => true,
		'compact_nullable_typehint' => true,
		'indentation_type' => true,
		'line_ending' => true,
		'method_chaining_indentation' => true,
		//'no_spaces_around_offset' => true, // conflict with wp.
		//'no_spaces_inside_parenthesis' => true, // conflict with wp.
		'no_trailing_whitespace' => true,
		//'no_whitespace_in_blank_line' => true,
		'single_blank_line_at_eof' => true,
		// 'statement_indentation' => true,
		//'types_spaces' => true,

	])
	->setUsingCache(false)
	->setRiskyAllowed(true)
	->setFinder($finder);

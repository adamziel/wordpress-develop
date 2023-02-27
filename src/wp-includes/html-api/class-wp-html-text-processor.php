<?php

// PHPUnit is slow to load, so I'll just run this file directly.
if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
	require __DIR__ . '/class-wp-html-attribute-token.php';
	require __DIR__ . '/class-wp-html-span.php';
	require __DIR__ . '/class-wp-html-text-replacement.php';
	require __DIR__ . '/class-wp-html-tag-processor.php';
	function esc_attr( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

define('HTML_DEBUG_MODE', false);
function dbg( $message, $indent = 0 ) {
	if( HTML_DEBUG_MODE ) {
		$indent = str_repeat( ' ', $indent * 2 );
		echo $indent . $message . "\n";
	}
}

class WP_HTML_Tag_Token {

	public $tag;

	public $bookmark;

	public function __construct( $tag, $bookmark = null ) {
		$this->tag = $tag;
		$this->bookmark = $bookmark;
	}

}

/**
 *
 */
class WP_HTML_Processor extends WP_HTML_Tag_Processor {

	private $MARKER;
	
	/**
	 * @var WP_HTML_Tag_Token[]
	 */
	private $open_elements = array();
	/**
	 * @var WP_HTML_Tag_Token[]
	 */
	private $active_formatting_elements = array();
	private $root_node                  = null;
	private $context_node               = null;

	/*
	 * WP_HTML_Tag_Processor skips over text nodes and only
	 * processes tags.
	 * 
	 * WP_HTML_Processor needs to process text nodes as well.
	 * 
	 * Whenever the tag processor skips over text to move to
	 * the next tag, the next_token() method emits that text 
	 * as a token and stores the tag in $buffered_tag to be
	 * returned the next time.
	 */
	private $buffered_tag = null;

	private $last_token = null;
	private $inserted_tokens = array();

	public $reconstructed_html = '';

	const MAX_BOOKMARKS = 1000000;

	public function __construct( $html ) {
		parent::__construct( $html );
		$this->MARKER = new WP_HTML_Tag_Token(null);
		$this->root_node     = new WP_HTML_Tag_Token( 'HTML' );
		$this->context_node  = new WP_HTML_Tag_Token( 'DOCUMENT' );
		$this->open_elements = array( $this->root_node );
	}

	public function parse() {
		echo("HTML before main loop:\n");
		echo($this->html);
		echo("\n");
		while ($this->next_node()) {
			// ... twiddle thumbs ...
		}

		while ( count($this->open_elements) > 1 ) {
			$this->pop_open_element();
		}

		echo("\n");
		echo("HTML after main loop:\n");
		echo($this->reconstructed_html.'');
		echo "\n\n";

		echo "Mem peak usage:" . memory_get_peak_usage(true) . "\n";
	}

	public function ignore_token() {
		// @TODO: remove the current tag from $this->html instead of
		//        not appending it to $this->reconstructed_html
		return $this->next_node();
	}

	public function next_node() {
		$text_start = $this->tag_ends_at + 1;
		
		$next_tag = false;
		if ( $this->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$bookmark = '__internal_' . ( $this->element_bookmark_idx++ );
			$this->set_bookmark($bookmark);
			$next_tag = new WP_HTML_Tag_Token(
				$this->get_tag(),
				$bookmark
			);
			$text_end = $this->bookmarks[$bookmark]->start;
		} else {
			$text_end = strlen($this->html);
		}

		if ($text_start < $text_end) {
			$text = substr($this->html, $text_start, $text_end - $text_start);
			dbg( "Found text node '$text'" );
			dbg( "Appending text to reconstructed HTML", 1 );
			$this->reconstruct_active_formatting_elements();
			// @TODO don't append stuff to $this->reconstructed_html
			//       instead, skip over the text in $this->html
			$this->reconstructed_html .= $text;
		}

		if ( ! $next_tag ) {
			return false;
		}

		$token = $next_tag;
		if ( ! $this->is_tag_closer() ) {
			dbg( "Found {$token->tag} tag opener" );
			switch ( $token->tag ) {
				case 'ADDRESS':
				case 'ARTICLE':
				case 'ASIDE':
				case 'BLOCKQUOTE':
				case 'CENTER':
				case 'DETAILS':
				case 'DIALOG':
				case 'DIR':
				case 'DIV':
				case 'DL':
				case 'FIELDSET':
				case 'FIGCAPTION':
				case 'FIGURE':
				case 'FOOTER':
				case 'HEADER':
				case 'HGROUP':
				case 'MAIN':
				case 'MENU':
				case 'NAV':
				case 'OL':
				case 'P':
				case 'SECTION':
				case 'SUMMARY':
				case 'UL':
					// Ignore special rules for 'PRE' and 'LISTING'
				case 'PRE':
				case 'LISTING':
					/*
					 * If the stack of open elements has a p element in button scope,
					 * then close a p element.
					 */
					if ( $this->is_element_in_button_scope( 'P' ) ) {
						$this->close_p_element();
					}
					$this->insert_element( $token );
					break;
				// A start tag whose tag name is "h1", "h2", "h3", "h4", "h5", or "h6"
				case 'H1':
				case 'H2':
				case 'H3':
				case 'H4':
				case 'H5':
				case 'H6':
					if ( $this->is_element_in_button_scope( 'P' ) ) {
						$this->close_p_element();
					}
					if ( in_array( $this->current_node()->tag, array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ) ) ) {
						$this->pop_open_element();
					}
					$this->insert_element( $token );
					break;
				case 'FORM':
					if ( $this->is_element_in_button_scope( 'P' ) ) {
						$this->close_p_element();
					}
					$this->insert_element( $token );
					break;
				case 'LI':
					$i = count( $this->open_elements ) - 1;
					while ( true ) {
						$node = $this->open_elements[ $i ];
						if ( $node->tag === 'LI' ) {
							$this->generate_implied_end_tags(
								array(
									'except_for' => array( 'LI' ),
								)
							);
							$this->pop_until_tag_name( 'LI' );
							break;
						} elseif ( self::is_special_element( $node->tag, array( 'ADDRESS', 'DIV', 'P' ) ) ) {
							break;
						} else {
							--$i;
							$node = $this->open_elements[ $i ];
						}
					}

					if ( $this->is_element_in_button_scope( 'P' ) ) {
						$this->close_p_element();
					}
					$this->insert_element( $token );
					break;
				case 'DD':
				case 'DT':
					$i = count( $this->open_elements ) - 1;
					while ( true ) {
						$node = $this->open_elements[ $i ];
						if ( $node->tag === 'DD' ) {
							$this->generate_implied_end_tags(
								array(
									'except_for' => array( 'DD' ),
								)
							);
							$this->pop_until_tag_name( 'DD' );
							break;
						} elseif ( $node->tag === 'DT' ) {
							$this->generate_implied_end_tags(
								array(
									'except_for' => array( 'DT' ),
								)
							);
							$this->pop_until_tag_name( 'DT' );
							break;
						} elseif ( self::is_special_element( $node->tag, array( 'ADDRESS', 'DIV', 'P' ) ) ) {
							break;
						} else {
							--$i;
							$node = $this->open_elements[ $i ];
						}
					}

					if ( $this->is_element_in_button_scope( 'P' ) ) {
						$this->close_p_element();
					}
					$this->insert_element( $token );
					break;
				case 'BUTTON':
					if ( $this->is_element_in_button_scope( 'BUTTON' ) ) {
						$this->generate_implied_end_tags();
						$this->pop_until_tag_name( 'BUTTON' );
					}
					$this->reconstruct_active_formatting_elements();
					$this->insert_element( $token );
					break;
				case 'A':
					$active_a = null;
					for ( $i = count( $this->active_formatting_elements ) - 1; $i >= 0; --$i ) {
						$node = $this->active_formatting_elements[ $i ];
						if ( $node->tag === 'A' ) {
							$active_a = $node;
							break;
						} elseif ( $this->MARKER !== $node ) {
							break;
						}
					}

					if ( $active_a ) {
						$this->parse_error();
						$this->adoption_agency_algorithm( $token );
					}

					$this->reconstruct_active_formatting_elements();
					$node = $this->insert_element( $token );
					$this->push_active_formatting_element( $node );
					break;
				case 'B':
				case 'BIG':
				case 'CODE':
				case 'EM':
				case 'FONT':
				case 'I':
				case 'S':
				case 'SMALL':
				case 'STRIKE':
				case 'STRONG':
				case 'TT':
				case 'U':
					$this->reconstruct_active_formatting_elements();
					$node = $this->insert_element( $token );
					$this->push_active_formatting_element( $node );
					break;
				case 'NOBR':
					$this->reconstruct_active_formatting_elements();
					if ( $this->is_element_in_scope( 'NOBR' ) ) {
						$this->parse_error();
						$this->adoption_agency_algorithm( $token );
						$this->reconstruct_active_formatting_elements();
					}
					$node = $this->insert_element( $token );
					$this->push_active_formatting_element( $node );
					break;
				case 'APPLET':
				case 'MARQUEE':
				case 'OBJECT':
					$this->reconstruct_active_formatting_elements();
					$this->insert_element( $token );
					$this->active_formatting_elements[] = $this->MARKER;
					break;
				case 'TABLE':
					$this->insert_element( $token );
					break;
				case 'AREA':
				case 'BR':
				case 'EMBED':
				case 'IMG':
				case 'KEYGEN':
				case 'WBR':
					$this->reconstruct_active_formatting_elements();
					$this->insert_element( $token );
					$this->pop_open_element();
					break;
				case 'PARAM':
				case 'SOURCE':
				case 'TRACK':
					$this->insert_element( $token );
					$this->pop_open_element();
					break;
				case 'HR':
					if ( $this->is_element_in_button_scope( 'P' ) ) {
						$this->close_p_element();
					}
					$this->insert_element( $token );
					$this->pop_open_element();
					break;
				case 'TEXTAREA':
					$this->insert_element( $token );
					break;
				case 'SELECT':
					$this->reconstruct_active_formatting_elements();
					$this->insert_element( $token );
					break;
				case 'OPTGROUP':
				case 'OPTION':
					if ( 'OPTION' === $token->tag ) {
						$this->pop_open_element();
					}
					$this->reconstruct_active_formatting_elements();
					$this->insert_element( $token );
					break;
				case 'RB':
				case 'RTC':
					if ( $this->is_element_in_scope( 'RB' ) || $this->is_element_in_scope( 'RTC' ) ) {
						$this->parse_error();
						$this->adoption_agency_algorithm( $token );
						$this->reconstruct_active_formatting_elements();
					}
					$this->insert_element( $token );
					break;
				case 'RP':
				case 'RT':
					if ( $this->is_element_in_scope( 'RP' ) || $this->is_element_in_scope( 'RT' ) ) {
						$this->parse_error();
						$this->adoption_agency_algorithm( $token );
						$this->reconstruct_active_formatting_elements();
					}
					$this->insert_element( $token );
					break;

				// case 'XMP':
				// case 'IFRAME':
				// case 'NOEMBED':
				// case 'MATH':
				// case 'SVG':
				// case 'NOSCRIPT':
				// case 'PLAINTEXT':
				// case 'IMAGE':
				// 	throw new Exception( $token->tag . ' not implemented yet' );

				default:
					$this->reconstruct_active_formatting_elements();
					$this->insert_element( $token );
					break;
			}
		} else {
			dbg( "Found {$token->tag} tag closer" );
			switch ( $token->tag ) {
				case 'ADDRESS':
				case 'ARTICLE':
				case 'ASIDE':
				case 'BLOCKQUOTE':
				case 'CENTER':
				case 'DETAILS':
				case 'DIALOG':
				case 'DIR':
				case 'DIV':
				case 'DL':
				case 'FIELDSET':
				case 'FIGCAPTION':
				case 'FIGURE':
				case 'FOOTER':
				case 'HEADER':
				case 'HGROUP':
				case 'MAIN':
				case 'MENU':
				case 'NAV':
				case 'OL':
				case 'PRE':
				case 'SECTION':
				case 'SUMMARY':
				case 'UL':
					if ( ! $this->is_element_in_scope( $token->tag ) ) {
						$this->parse_error();
						return $this->ignore_token();
					}
					$this->generate_implied_end_tags();
					$this->pop_until_tag_name( $token->tag );
					break;
				case 'FORM':
					$this->generate_implied_end_tags();
					$this->pop_until_tag_name( $token->tag );
					break;
				case 'P':
					/*
					 * If the stack of open elements does not have a p element in button scope, 
					 * then this is a parse error; insert an HTML element for a "p" start tag 
					 * token with no attributes.
					 */
					if ( ! $this->is_element_in_button_scope( 'P' ) ) {
						$this->parse_error();
						$this->insert_element( new WP_HTML_Tag_Token( 'P' ) );
					}
					// Close a p element.
					$this->close_p_element();
					break;
				case 'LI':
					if ( ! $this->is_element_in_list_item_scope( 'LI' ) ) {
						$this->parse_error();
						return $this->ignore_token();
					}
					$this->generate_implied_end_tags();
					$this->pop_until_tag_name( 'LI' );
					break;
				case 'DD':
				case 'DT':
					if ( ! $this->is_element_in_scope( $token->tag ) ) {
						$this->parse_error();
						return $this->ignore_token();
					}
					$this->generate_implied_end_tags();
					$this->pop_until_tag_name( $token->tag );
					break;
				case 'H1':
				case 'H2':
				case 'H3':
				case 'H4':
				case 'H5':
				case 'H6':
					if ( ! $this->is_element_in_scope( array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ) ) ) {
						$this->parse_error();
						return $this->ignore_token();
					}
					$this->generate_implied_end_tags();
					$this->pop_until_tag_name( array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ) );
					break;
				case 'A':
				case 'B':
				case 'BIG':
				case 'CODE':
				case 'EM':
				case 'FONT':
				case 'I':
				case 'S':
				case 'SMALL':
				case 'STRIKE':
				case 'STRONG':
				case 'TT':
				case 'U':
					dbg( "Found {$token->tag} tag closer" );
					$this->adoption_agency_algorithm( $token );
					break;

				case 'APPLET':
				case 'MARQUEE':
				case 'OBJECT':
					if ( ! $this->is_element_in_scope( $token->tag ) ) {
						$this->parse_error();
						return $this->ignore_token();
					}
					$this->generate_implied_end_tags();
					if ( $this->current_node()->tag !== $token->tag ) {
						$this->parse_error();
					}
					$this->pop_until_tag_name( $token->tag );
					$this->clear_active_formatting_elements_up_to_last_marker();
					break;
				case 'BR':
					// This should never happen since Tag_Processor corrects that
				default:
					$i = count( $this->open_elements ) - 1;
					while ( true ) {
						$node = $this->open_elements[ $i ];
						if ( $node->tag === $token->tag ) {
							$this->generate_implied_end_tags(
								array(
									'except_for' => array( $token->tag ),
								)
							);
							$this->pop_until_node( $node );
							break;
						} elseif ( $this->is_special_element( $node->tag ) ) {
							$this->parse_error();
							return $this->ignore_token();
						} else {
							--$i;
						}
					}
					break;
			}
		}
		return $token;
	}

	private $element_bookmark_idx = 0;
	private function next_token() {
		if($this->buffered_tag){
			$next_tag = $this->buffered_tag;
			$this->buffered_tag = null;
			return $next_tag;
		}

		$next_tag = false;
		if ( $this->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$bookmark = '__internal_' . ( $this->element_bookmark_idx++ );
			$this->set_bookmark($bookmark);
			$attributes = array();
			$attrs = $this->get_attribute_names_with_prefix('');
			if ($attrs) {
				foreach ($attrs as $name) {
					$attributes[$name] = $this->get_attribute($name);
				}
			}
			$next_tag = new WP_HTML_Tag_Token(
				$this->get_tag(),
				$bookmark
			);
			$text_end = $this->bookmarks[$bookmark]->start;
		} else {
			$text_end = strlen($this->html);
		}

		/*
		 * If any text was found between the last tag and this one, 
		 * save the next tag for later and return the text token.
		 */
		$last = $this->last_token;
		if ( 
			$last
			&& $last->bookmark
			&& $this->has_bookmark($last->bookmark)
		) {
			$text_start = $this->bookmarks[$last->bookmark]->end + 1;
			if ($text_start < $text_end) {
				$this->buffered_tag = $next_tag;
				$text = substr($this->html, $text_start, $text_end - $text_start);
				return $text;
			}
		}

		return $next_tag;
	}

	const ANY_OTHER_END_TAG = 1;
	private function adoption_agency_algorithm( WP_HTML_Tag_Token $token ) {
		dbg("Adoption Agency Algorithm", 1);
		$subject = $token->tag;
		$current_node = $this->current_node();
		if (
			$current_node->tag === $subject
			&& ! in_array( $current_node, $this->active_formatting_elements, true )
		) {
			$this->pop_open_element();
			dbg("Skipping AAA: current node is \$subject ($subject) and is not AFE", 2);
			return;
		}

		$outer_loop_counter = 0;
		while ( ++$outer_loop_counter < 8 ) {
			/*
			 * Let __formatting element__ be the last element in the list of active
			 * formatting elements that:
			 *    - is between the end of the list and the last marker in the list,
			 *      if any, or the start of the list otherwise, and
			 *    - has the same tag name as the token.
			 */
			$formatting_element     = null;
			$formatting_element_idx = -1;
			for ( $i = count( $this->active_formatting_elements ) - 1; $i >= 0; $i-- ) {
				$candidate = $this->active_formatting_elements[ $i ];
				if ( $this->MARKER === $candidate ) {
					break;
				}
				if ( $candidate->tag === $subject ) {
					$formatting_element     = $candidate;
					$formatting_element_idx = $i;
					break;
				}
			}

			// If there is no such element, then abort these steps and instead act as
			// described in the "any other end tag" entry below.
			if ( null === $formatting_element ) {
				dbg("Skipping AAA: no formatting element found", 2);
				return self::ANY_OTHER_END_TAG;
			}
			dbg("AAA: Formatting element = {$formatting_element->tag}", 2);

			// If formatting element is not in the stack of open elements, then this is
			// a parse error; remove the element from the list, and return.
			if ( ! in_array( $formatting_element, $this->open_elements, true ) ) {
				array_splice( $this->active_formatting_elements, $formatting_element_idx, 1 );
				$this->parse_error();
				dbg("Skipping AAA: formatting element is not in the stack of open elements", 2);
				return;
			}

			// If formatting element is not in scope, then this is a parse error; return
			if ( ! $this->is_element_in_scope( $formatting_element ) ) {
				$this->parse_error();
				dbg("Skipping AAA: formatting element {$formatting_element->tag} is not in scope", 2);
				$this->print_open_elements('Open elements: ', 2);
				return;
			}

			// If formatting element is not the current node, then this is a parse error.
			// (But do not return.)
			if ( $formatting_element !== $this->current_node() ) {
				$this->parse_error();
			}

			/*
			 * Let furthest block be the topmost node in the stack of open elements that
			 * is lower in the stack than formatting element, and is an element in the
			 * special category. There might not be one.
			 */
			$furthest_block = null;
			for ( $i = count( $this->open_elements ) - 1; $i >= 0; $i-- ) {
				$node = $this->open_elements[ $i ];
				if ( $node === $formatting_element ) {
					break;
				}
				if ( $this->is_special_element( $node->tag ) ) {
					$furthest_block = $node;
				}
			}

			// If there is no such node, then the UA must first pop all the nodes from
			// the bottom of the stack of open elements, from the current node up to
			// and including formatting element, then remove formatting element from
			// the list of active formatting elements, and finally abort these steps.
			if ( null === $furthest_block ) {
				$this->pop_until_node( $formatting_element );
				array_splice( $this->active_formatting_elements, $formatting_element_idx, 1 );
				dbg("Skipping AAA: no furthest block found", 2);
				return;
			}

			// We didn't bale out so far, but the algorithm is not implemented.
			// Let's error out.
			break;
		}
		throw new Exception('Adoption Agency Algorithm not supported.');
	}

	private function insert_element( WP_HTML_Tag_Token $token ) {
		// Text API:
		// @TODO: do nothing if $token is already in $this->html
		//        instead of building $this->reconstructed_html
		//        from scratch
		// @TODO attrs
		$this->reconstructed_html .= '<'.$token->tag.'>';
		array_push($this->open_elements, $token);
		return $token;
	}

	private function parse_error() {
		// Noop for now
	}

	private function pop_until_tag_name( $tags ) {
		if ( ! is_array( $tags ) ) {
			$tags = array( $tags );
		}
		dbg( "Popping until tag names: " . implode(', ', $tags), 1 );
		$this->print_open_elements( "Open elements before: " );
		do {
			$popped = $this->pop_open_element();
		} while (!in_array($popped->tag, $tags));
		$this->print_open_elements( "Open elements after: " );
	}

	private function pop_until_node( $node ) {
		do {
			$popped = $this->pop_open_element();
		} while ( $popped !== $node );
	}

	private function pop_open_element() {
		$popped = array_pop( $this->open_elements );

		// Text API:
		$this->reconstructed_html .= '</'.$popped->tag.'>';

		// Object-oriented API:
		if ( $popped->bookmark ) {
			$this->release_bookmark( $popped->bookmark );
		}
		return $popped;
	}

	private function generate_implied_end_tags( $options = null ) {
		while ( $this->should_generate_implied_end_tags( $options ) ) {
			yield $this->pop_open_element();
		}
	}

	private function current_node() {
		return end( $this->open_elements );
	}

	private function close_p_element() {
		dbg( "close_p_element" );
		$this->generate_implied_end_tags(
			array(
				'except_for' => array( 'P' ),
			)
		);
		// If the current node is not a p element, then this is a parse error.
		if ( $this->get_tag() !== 'P' ) {
			$this->parse_error();
		}
		$this->pop_until_tag_name( 'P' );
	}

	private function should_generate_implied_end_tags( $options = null ) {
		$current_tag_name = $this->get_tag();
		if ( null !== $options && isset( $options['except_for'] ) && in_array( $current_tag_name, $options['except_for'] ) ) {
			return false;
		}
		switch ( $current_tag_name ) {
			case 'DD':
			case 'DT':
			case 'LI':
			case 'OPTION':
			case 'OPTGROUP':
			case 'P':
			case 'RB':
			case 'RP':
			case 'RT':
			case 'RTC':
				return true;
		}

		$thoroughly = null !== $options && isset( $options['thoroughly'] ) && $options['thoroughly'];
		if ( $thoroughly ) {
			switch ( $current_tag_name ) {
				case 'TBODY':
				case 'TFOOT':
				case 'THEAD':
				case 'TD':
				case 'TH':
				case 'TR':
					return true;
			}
		}

		return false;
	}

	/**
	 * https://html.spec.whatwg.org/multipage/parsing.html#the-list-of-active-formatting-elements
	 */
	private function push_active_formatting_element( WP_HTML_Tag_Token $node ) {
		$count = 0;
		for ( $i = count( $this->active_formatting_elements ) - 1; $i >= 0; $i-- ) {
			$formatting_element = $this->active_formatting_elements[ $i ];
			if ( $this->MARKER !== $formatting_element ) {
				break;
			}
			if ( $formatting_element !== $node ) {
				continue;
			}
			$count++;
			if ( $count === 3 ) {
				array_splice( $this->active_formatting_elements, $i, 1 );
				break;
			}
		}
		$this->active_formatting_elements[] = $node;
	}

	private function print_active_formatting_elements($msg, $indent=1) {
		if (HTML_DEBUG_MODE) {
			$formats = array_map(function ($node) {
				return $this->MARKER === $node ? 'M' : ($node->tag ?: 'ERROR');
			}, $this->active_formatting_elements);
			dbg("$msg " . implode(', ', $formats), $indent);
		}
	}

	private function print_open_elements($msg, $indent=1) {
		if (HTML_DEBUG_MODE) {
			$elems = array_map(function ($node) {
				return $node->tag;
			}, $this->open_elements);
			dbg("$msg " . implode(', ', $elems), $indent);
		}
	}

	private function reconstruct_active_formatting_elements() {
		$this->print_active_formatting_elements('AFE: before');
		if ( empty( $this->active_formatting_elements ) ) {
			dbg( "Skipping AFE: empty list", 1 );
			return;
		}
		$entry_idx          = count( $this->active_formatting_elements ) - 1;
		$last_entry = $this->active_formatting_elements[ $entry_idx ];
		if ( $this->MARKER === $last_entry || in_array( $last_entry, $this->open_elements, true ) ) {
			dbg( "Skipping AFE: marker or open element", 1 );
			return;
		}

		// Let entry be the last (most recently added) element in the list of active formatting elements.
		$entry = $last_entry;

		$is_rewinding = true;
		while ( true ) {
			if ( $is_rewinding ) {
				// Rewind:
				/*
				 * If there are no entries before entry in the list of active formatting elements,
				 * then jump to the step labeled create.
				 */
				if ( $entry_idx === 0 ) {
					$is_rewinding = false;
				} else {
					// Let entry be the entry one earlier than entry in the list of active formatting elements.
					$entry = $this->active_formatting_elements[ --$entry_idx ];

					// If entry is neither a marker nor an element that is also in the stack of open elements,
					// go to the step labeled rewind.
					if ( $this->MARKER !== $entry && ! in_array( $entry, $this->open_elements, true ) ) {
						continue;
					}
				}
			} else {
				// Advance:
				// Let entry be the element one later than entry in the list of active formatting elements.
				$entry = $this->active_formatting_elements[ ++$entry_idx ];
			}

			// Create: Insert an HTML element for the token for which the element entry was created,
			// to obtain new element.
			$new_element = $this->insert_element( $entry );

			// Replace the entry for entry in the list with an entry for new element.
			$this->active_formatting_elements[ $entry_idx ] = $new_element;

			// If the entry for new element in the list of active formatting elements is not the last entry 
			// in the list, return to the step labeled advance.
			if ( $entry_idx === count( $this->active_formatting_elements ) - 1 ) {
				break;
			}
		}
		$this->print_active_formatting_elements('AFE: after');
	}

	private function clear_active_formatting_elements_up_to_last_marker() {
		while ( ! empty( $this->active_formatting_elements ) ) {
			$entry = array_pop( $this->active_formatting_elements );
			if ( $this->MARKER === $entry ) {
				break;
			}
		}
	}

	/**
	 * The stack of open elements is said to have a particular element in 
	 * select scope when it has that element in the specific scope consisting
	 * of all element types except the following:
	 * * optgroup
	 * * option
	 */
	private function is_element_in_select_scope( $target_node ) {
		return $this->is_element_in_specific_scope(
			$target_node,
			array(
				'OPTGROUP',
				'OPTION',
			),
			array(
				'negative_match' => 'true',
			)
		);
	}

	private function is_element_in_table_scope( $target_node ) {
		return $this->is_element_in_specific_scope(
			$target_node,
			array(
				'HTML',
				'TABLE',
				'TEMPLATE',
			)
		);
	}

	private function is_element_in_button_scope( $target_node ) {
		return $this->is_element_in_scope(
			$target_node,
			array(
				'BUTTON',
			)
		);
	}

	private function is_element_in_list_item_scope( $target_node ) {
		return $this->is_element_in_scope(
			$target_node,
			array(
				'LI',
				'DD',
				'DT',
			)
		);
	}

	private function is_element_in_scope( $target_node, $additional_elements = array() ) {
		return $this->is_element_in_specific_scope(
			$target_node,
			array_merge(
				array(
					'APPLET',
					'CAPTION',
					'HTML',
					'TABLE',
					'TD',
					'TH',
					'MARQUEE',
					'OBJECT',
					'TEMPLATE',
				),
				$additional_elements
			)
		);
	}

	/*
	 * https://html.spec.whatwg.org/multipage/parsing.html#the-stack-of-open-elements
	 */
	private function is_element_in_specific_scope( $target_node, $element_types_list, $options = array() ) {
		$negative_match = isset( $options['negative_match'] ) ? $options['negative_match'] : false;

		/**
		 * The stack of open elements is said to have an element target node in a
		 * specific scope consisting of a list of element types list when the following
		 * algorithm terminates in a match state:
		 */
		$i = count( $this->open_elements ) - 1;
		// 1. Initialize node to be the current node (the bottommost node of the stack).
		$node = $this->open_elements[ $i ];

		while ( true ) {
			// 2. If node is the target node, terminate in a match state.
			if ( is_string( $target_node ) ) {
				if ( $node->tag === $target_node ) {
					return true;
				}
			} else if ( $node === $target_node ) {
				return true;
			}

			// 3. Otherwise, if node is one of the element types in list, terminate in a failure state.
			$failure = in_array( $node->tag, $element_types_list, true );

			// Some elements say:
			// > If has that element in the specific scope consisting of all element types 
			// > except the following
			// So we need to invert the result.
			if($negative_match) {
				$failure = ! $failure;
			}
			if ( $failure ) {
				return false;
			}

			// Otherwise, set node to the previous entry in the stack of open elements and
			// return to step 2. (This will never fail, since the loop will always terminate 
			// in the previous step if the top of the stack — an html element — is reached.)
			$node = $this->open_elements[ --$i ];
		}
	}

	private static function is_special_element( $tag_name, $except = null ) {
		if ( null !== $except && in_array( $tag_name, $except, true ) ) {
			return false;
		}

		switch ( $tag_name ) {
			case 'ADDRESS':
			case 'APPLET':
			case 'AREA':
			case 'ARTICLE':
			case 'ASIDE':
			case 'BASE':
			case 'BASEFONT':
			case 'BGSOUND':
			case 'BLOCKQUOTE':
			case 'BODY':
			case 'BR':
			case 'BUTTON':
			case 'CAPTION':
			case 'CENTER':
			case 'COL':
			case 'COLGROUP':
			case 'DD':
			case 'DETAILS':
			case 'DIR':
			case 'DIV':
			case 'DL':
			case 'DT':
			case 'EMBED':
			case 'FIELDSET':
			case 'FIGCAPTION':
			case 'FIGURE':
			case 'FOOTER':
			case 'FORM':
			case 'FRAME':
			case 'FRAMESET':
			case 'H1':
			case 'H2':
			case 'H3':
			case 'H4':
			case 'H5':
			case 'H6':
			case 'HEAD':
			case 'HEADER':
			case 'HGROUP':
			case 'HR':
			case 'HTML':
			case 'IFRAME':
			case 'IMG':
			case 'INPUT':
			case 'ISINDEX':
			case 'LI':
			case 'LINK':
			case 'LISTING':
			case 'MAIN':
			case 'MARQUEE':
			case 'MENU':
			case 'MENUITEM':
			case 'META':
			case 'NAV':
			case 'NOEMBED':
			case 'NOFRAMES':
			case 'NOSCRIPT':
			case 'OBJECT':
			case 'OL':
			case 'P':
			case 'PARAM':
			case 'PLAINTEXT':
			case 'PRE':
			case 'SCRIPT':
			case 'SECTION':
			case 'SELECT':
			case 'SOURCE':
			case 'STYLE':
			case 'SUMMARY':
			case 'TABLE':
			case 'TBODY':
			case 'TD':
			case 'TEMPLATE':
			case 'TEXTAREA':
			case 'TFOOT':
			case 'TH':
			case 'THEAD':
			case 'TITLE':
			case 'TR':
			case 'TRACK':
			case 'UL':
			case 'WBR':
			case 'XMP':
				return true;
			default:
				return false;
		}
	}

	private static function is_rcdata_element( $tag_name ) {
		switch ( $tag_name ) {
			case 'TITLE':
			case 'TEXTAREA':
			case 'STYLE':
			case 'XMP':
			case 'IFRAME':
			case 'NOEMBED':
			case 'NOFRAMES':
			case 'NOSCRIPT':
				return true;
			default:
				return false;
		}
	}

	private static function is_formatting_element( $tag_name ) {
		switch ( strtoupper( $tag_name ) ) {
			case 'A':
			case 'B':
			case 'BIG':
			case 'CODE':
			case 'EM':
			case 'FONT':
			case 'I':
			case 'NOBR':
			case 'S':
			case 'SMALL':
			case 'STRIKE':
			case 'STRONG':
			case 'TT':
			case 'U':
				return true;
			default:
				return false;
		}
	}

}

// $dir = realpath( __DIR__ . '/../../../index.html' );

// $htmlspec = file_get_contents( $dir );
// $p = new WP_HTML_Processor( $htmlspec );
// $p->parse();

// die();

$p = new WP_HTML_Processor( '<p>1<b>2<i>3</b>4</i>5</p>' );
$p->parse();

$p = new WP_HTML_Processor( '<div>1<span>2</div>3</span>4' );
$p->parse();

$p = new WP_HTML_Processor( '<ul><li>1<li>2<li>3<li>Lorem<b>Ipsum<li>Dolor</ul></ul></ul><span></ul>Sit<span>Sit<span><div>Amet' );
$p->parse();
die();
/*
Outputs:

DOM after main loop:
  HTML
   ├─ UL
      ├─ LI
         └─ #text: 1
      ├─ LI
         └─ #text: 2
      ├─ LI
         └─ #text: 3
      ├─ LI
         ├─ #text: Lorem
         └─ B
            └─ #text: Ipsum
      └─ LI
         └─ B
            └─ #text: Dolor
   └─ B
      └─ SPAN
         ├─ #text: Sit
         └─ SPAN
            ├─ #text: Sit
            └─ SPAN
               └─ DIV
                  └─ #text: Amet
*/

$p = new WP_HTML_Processor( '<b>
<div>
   <div></div>
   </b>
 </div>
</b>' );
$p->parse();
// $p = new WP_HTML_Processor( '<b>1<p><div>2</b>3</p></div>' );
// $p->parse();
// /*
// Outputs the correct result:
// B
// └─ #text: 1
// P
// ├─ B
//    └─ #text: 2
// └─ #text: 3
// */
echo "\n\n";
echo $p->reconstructed_html;
die();

$p = new WP_HTML_Processor( '<p><b class=x><b class=x><b><b class=x><b class=x><b><b class=x><b class=x><b><b class=x><b class=x><b>X
<p>X
<p><b><b class=x><b>X
<p></b></b></b></b></b></b>X' );
$p->parse();
/*
DOM after main loop:
  HTML
   ├─ P
      └─ B class="x"
         └─ B class="x"
            └─ B
               └─ B class="x"
                  └─ B class="x"
                     └─ B
                        └─ #text: X
   ├─ P
      └─ B class="x"
         └─ B
            └─ B class="x"
               └─ B class="x"
                  └─ B
                     └─ #text: X
   ├─ P
      └─ B class="x"
         └─ B
            └─ B class="x"
               └─ B class="x"
                  └─ B
                     └─ B
                        └─ B class="x"
                           └─ B
                              └─ #text: X
   └─ P
      └─ #text: X
*/

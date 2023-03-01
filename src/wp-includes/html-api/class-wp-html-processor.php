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

// It's an object because sometimes the identity matters
class WP_HTML_Tag_Token {

	public $tag;

	public function __construct( $tag ) {
		$this->tag = $tag;
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
	public $active_formatting_elements = array();

	private $element_bookmark_idx = 0;
	private $current_token;
	private $current_token_start;
	private $current_token_end;

	const MAX_BOOKMARKS = 1000000;

	public function __construct( $html ) {
		parent::__construct( $html );
		$this->MARKER = new WP_HTML_Tag_Token(null);
		$this->open_elements = array( 
			new WP_HTML_Tag_Token( 'HTML' )
		);
	}

	public function parse() {
		echo("HTML before main loop:\n");
		// echo($this->html);
		echo("\n");
		$i = 0;
		while ($this->next_tag()) {
			// ... twiddle thumbs ...
			if(++$i % 10000 === 0)
			{
				echo $this->get_tag()." oe: " . count($this->open_elements) . " ";
				echo "afe: " . count($this->active_formatting_elements) . " \n";
				echo "Peak mem:" . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . "MB\n";
				// print_r($this->open_elements);
				// die();
			}
		}

		echo("\n");
		echo("\$this->HTML after main loop:\n");
		// echo($this->get_updated_html().'');
		echo "\n\n";

		echo "Mem peak usage:" . (memory_get_peak_usage(true) / 1024 / 1024) . "MB\n";
		echo("\n---------------\n\n");
		return $this->get_updated_html();
	}

	private $parser_bookmarks = array();
	/**
	 * Sets a bookmark for the parser
	 * 
	 * @TODO: make $pinned purely internal
	 * @see WP_HTML_Tag_Processor::set_bookmark()
	 * @param mixed $name Name of the bookmark
	 * @param mixed $pinned Protects a bookmark from being released by release_bookmark()
	 *                         Useful for outer_html().
	 * @return bool Whether the bookmark was set
	 */
	public function set_bookmark( $name, $pinned = false ) {
		if ( ! parent::set_bookmark($name) ) {
			unset($this->parser_bookmarks[$name]);
			return false;
		}

		/**
		 * seek() will rewing before the current tag
		 * and consume it again. We need to remove the
		 * top element from element stacks to avoid
		 * to duplicates.
		 */
		$open_elements = $this->open_elements;
		if(end($open_elements) === $this->current_token) {
			array_pop($open_elements);
		}

		$active_formatting_elements = $this->active_formatting_elements;
		if(end($active_formatting_elements) === $this->current_token) {
			array_pop($active_formatting_elements);
		}

		$this->parser_bookmarks[$name] = array(
			'open_elements' => $open_elements,
			'active_formatting_elements' => $active_formatting_elements,

			// Pinned bookmarks are protected from release_bookmark()
			// Also, their position won't change.
			'pinned' => $pinned,
			'start' => $this->bookmarks[$name]->start,
			'end' => $this->bookmarks[$name]->end,
		);
		return true;
	}

	/**
	 * Releases a bookmark for the parser
	 * 
	 * @TODO: make $force purely internal
	 * @see WP_HTML_Tag_Processor::set_bookmark()
	 * @param mixed $name Name of the bookmark
	 * @param mixed $force Whether to release the bookmark even if it's protected
	 * @return bool Whether the bookmark was set
	 */
	public function release_bookmark( $bookmark, $force = false ) {
		if ( !isset($this->parser_bookmarks[$bookmark]) ){
			return false;
		}
		// Pinned bookmarks are protected from release_bookmark()
		if( !$force && $this->parser_bookmarks[$bookmark]['pinned']) {
			return false;
		}
		unset($this->parser_bookmarks[$bookmark]);
		return parent::release_bookmark($bookmark);
	}

	public function seek($bookmark_name) {
		if ( !isset($this->parser_bookmarks[$bookmark_name]) ){
			return false;
		}
		// Pinned bookmarks position won't change when applying
		// lexical updates
		if($this->parser_bookmarks[$bookmark_name]['pinned']) {
			$this->bookmarks[$bookmark_name]->start = $this->parser_bookmarks[$bookmark_name]['start'];
			$this->bookmarks[$bookmark_name]->end = $this->parser_bookmarks[$bookmark_name]['end'];
		}
		if(!$this->seek_without_consuming($bookmark_name)) {
			return false;
		}

		$b = $this->parser_bookmarks[$bookmark_name];
		$this->current_token = null;
		$this->open_elements = $b['open_elements'];
		$this->active_formatting_elements = $b['active_formatting_elements'];
		return $this->next_tag();
	}

	private function print_open_elements() {
		echo "Open elements: ";
		foreach($this->open_elements as $oe) {
			echo $oe->tag . " > ";
		}
		echo "\n";
	}
	private function print_active_formatting_elements() {
		echo "AFE: ";
		foreach($this->active_formatting_elements as $afe) {
			echo $afe->tag . " > ";
		}
		echo "\n";
	}

	public function depth() {
		// -1 because the root HTML element is not counted
		return count($this->open_elements) - 1 + (
			$this->is_tag_closer() ? 1 : 0
		);
	}

	public function first_child()
	{
		return $this->nth_child(1);
	}

	public function nth_child($n=1) {
		if ( null === $this->tag_name_starts_at ) {
			return $this->next_node();
		}
		if ( ! $this->set_bookmark('internal_nth_child') ) {
			return false;
		}
		$depth = $this->depth();
		$matched = 0;
		try {
			do {
				if (!$this->next_node()) {
					return false;
				}

				if ($this->is_tag_closer()) {
					continue;
				}

				if ($this->depth() <= $depth) {
					$this->seek('internal_nth_child');
					return false;
				}

				if ($this->depth() !== $depth + 1) {
					continue;
				}

				++$matched;
			} while ($matched < $n);
			return true;
		} finally {
			$this->release_bookmark('internal_nth_child');
		}
	}

	public function next_sibling()
	{
		return $this->nth_sibling(1);
	}

	public function nth_sibling($n = 1)
	{
		if ( null === $this->tag_name_starts_at ) {
			return $this->next_node();
		}
		if ( ! $this->set_bookmark('internal_nth_sibling') ) {
			return false;
		}
		$depth = $this->depth();
		$matched = 0;
		try {
			do {
				if (!$this->next_node()) {
					return false;
				}

				if ($this->is_tag_closer()) {
					continue;
				}

				if ($this->depth() > $depth) {
					continue;
				}

				if ($this->depth() < $depth) {
					if(!$this->seek('internal_nth_sibling')) {
						throw new Exception('Failed to seek to internal_nth_sibling');
					}
					return false;
				}

				++$matched;
			} while ($matched < $n);
			return true;
		} finally {
			$this->release_bookmark('internal_nth_sibling');
		}
	}

	public function inner_html($html=null) {
		if ( null === $this->tag_name_starts_at ) {
			return null;
		}

		if(!$this->set_bookmark('internal_inner_html')) {
			return false;
		}
		try {
			if(!$this->balancing_closer()) {
				return false;
			}
			$tag_closer_starts_at = $this->tag_name_starts_at - 2;

			// Return to the initial cursor position
			// @TODO: Don't seek if balancing_closer didn't update
			//        the HTML
			if(!$this->seek('internal_inner_html')) {
				throw new Exception('Failed to seek to internal_inner_html bookmark');
			}

			$content_starts_at = $this->tag_ends_at + 1;
			if(null === $html) {
				// Get the inner HTML
				return substr($this->html, $content_starts_at, $tag_closer_starts_at - $content_starts_at);
			}
	
			// Set the inner HTML
			$this->add_lexical_update(
				new WP_HTML_Text_Replacement(
					$content_starts_at,
					$tag_closer_starts_at,
					$html
				)
			);
			$this->flush_updates();

			// Flush lexical updates
			if(!$this->seek('internal_inner_html')) {
				throw new Exception('Failed to seek to internal_inner_html bookmark');
			}
			return true;
		} finally {
			$this->release_bookmark('internal_inner_html');
		}
	}

	public function outer_html($html=null) {
		if ( null === $this->tag_name_starts_at ) {
			return null;
		}

		$this->get_updated_html();
		if(!$this->set_bookmark('internal_outer_html', true)) {
			return false;
		}
		try {
			if(!$this->balancing_closer()) {
				return false;
			}
			$tag_closer_ends_at = $this->tag_ends_at;

			// Return to the initial cursor position
			// @TODO: Don't seek if balancing_closer didn't update
			//        the HTML
			if(!$this->seek('internal_outer_html')) {
				throw new Exception('Failed to seek to internal_outer_html bookmark');
			}
			$tag_starts_at = $this->tag_name_starts_at - 1;

			if(null === $html) {
				// Get the inner HTML
				return substr($this->html, $tag_starts_at, $tag_closer_ends_at + 1 - $tag_starts_at);
			}

			// Set the inner HTML
			$this->add_lexical_update(
				new WP_HTML_Text_Replacement(
					$tag_starts_at,
					$tag_closer_ends_at + 1,
					$html
				)
			);
			$this->flush_updates();

			// var_dump($this->open_elements);
			if(!$this->seek('internal_outer_html')) {
				throw new Exception('Failed to seek to internal_outer_html bookmark');
			}
			
			return true;
		} finally {
			$this->release_bookmark('internal_outer_html');
		}
	}


	public function balancing_closer() {
		if($this->is_tag_closer()) {
			return false;
		}
		/*
		 * There might be tag closers buffered for insertion,
		 * let's flush any updates we might have at this point.
		 */
		$this->get_updated_html();
		if(!$this->set_bookmark('internal_balancing_closer')) {
			return false;
		}
		try {
			$depth = $this->depth();
			$token = $this->current_token;
			while($this->next_tag()) {
				if(
					// Current element popped off the stack
					$this->depth() <= $depth 
					&& end($this->open_elements) !== $token
				) {
					/**
					 * The entire tag contents have been parsed,
					 * let's seek to the opener and read the inner
					 * HTML with missing tag closers added back in
					 */
					break;
				}
			}

			if(!$this->seek('internal_balancing_closer')){
				throw new Exception('Failed to seek to internal_balancing_closer bookmark');
			}

			while($this->next_tag()) {
				if(
					// Current element popped off the stack
					$this->depth() < $depth 
					// Stack is the same size, but the current element was popped
					|| ($this->depth() === $depth && end($this->open_elements) !== $token)
				) {
					if ($this->is_tag_closer()) {
						return true;
					}
					break;
				}
			}
			
			// Should never ever happen
			throw new Exception('Critical parser error: no matching closer found');
		} finally {
			$this->release_bookmark('internal_balancing_closer');
		}
	}

	public function next_node() {
		while ($this->next_tag()) {
			// is_tag_closer can be NULL if `next_tag`
			// didn't find a tag closer
			if (false === $this->is_tag_closer()) {
				return true;
			}
		}
		return false;
	}

	private $is_closing_open_tags = false;
	public function next_tag($query = null) {
		/*
		 * We're done with the document but some tags
		 * are still open. Let's close them one at a time.
		 */
		if ( $this->is_closing_open_tags ) {
			// If only the root element is open, we're done.
			if(count($this->open_elements) <= 1)
			{
				return false;
			}

			// Otherwise close the next open tag on the stack
			$this->current_token = null;
			$this->current_token_start = strlen($this->html);
			$this->current_token_end = strlen($this->html);

			$this->pop_open_element();
			$this->get_updated_html();

			parent::next_tag(array('tag_closers' => 'visit'));
			$this->current_token = new WP_HTML_Tag_Token($this->get_tag());
			$this->current_token_start = $this->tag_name_starts_at - 2;
			$this->current_token_end = $this->tag_ends_at;
			return true;
		}
		
		/**
		 * Go to the next tag and process any text was found along the way.
		 */
		$text_start = $this->tag_ends_at + 1;
		if (!parent::next_tag(array('tag_closers' => 'visit'))) {
			$this->process_text($text_start, strlen($this->html));

			$this->is_closing_open_tags = true;
			return $this->next_tag();
		}

		/**
		 * We found a tag! Let's process any text we may have found along the way.
		 */
		$current_tag_start = $this->tag_name_starts_at - ( $this->is_closing_tag ? 2 : 1 );
		$this->process_text($text_start, $current_tag_start);

		$this->current_token = new WP_HTML_Tag_Token($this->get_tag());
		$this->current_token_start = $current_tag_start;
		$this->current_token_end = $this->tag_ends_at;

		if ( ! $this->is_tag_closer() ) {
			dbg( "Found {$this->current_token->tag} tag opener" );
			switch ( $this->current_token->tag ) {
				case 'HTML':
					$this->drop_current_tag_token();
					break;
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
					$this->insert_element( $this->current_token );
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
					$this->insert_element( $this->current_token );
					break;
				case 'FORM':
					if ( $this->is_element_in_button_scope( 'P' ) ) {
						$this->close_p_element();
					}
					$this->insert_element( $this->current_token );
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
							$this->pop_until_tag( 'LI' );
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
					$this->insert_element( $this->current_token );
					break;
				case 'DD':
				case 'DT':
					$i = count( $this->open_elements );
					while ( $i > 0 ) {
						$node = $this->open_elements[ --$i ];
						if ( $node->tag === 'DD' ) {
							$this->generate_implied_end_tags(
								array(
									'except_for' => array( 'DD' ),
								)
							);
							$this->pop_until_tag( 'DD' );
							break;
						} elseif ( $node->tag === 'DT' ) {
							$this->generate_implied_end_tags(
								array(
									'except_for' => array( 'DT' ),
								)
							);
							$this->pop_until_tag( 'DT' );
							break;
						} elseif ( self::is_special_element( $node->tag, array( 'ADDRESS', 'DIV', 'P' ) ) ) {
							break;
						}
					}

					if ( $this->is_element_in_button_scope( 'P' ) ) {
						$this->close_p_element();
					}
					$this->insert_element( $this->current_token );
					break;
				case 'BUTTON':
					if ( $this->is_element_in_button_scope( 'BUTTON' ) ) {
						$this->generate_implied_end_tags();
						$this->pop_until_tag( 'BUTTON' );
					}
					$this->reconstruct_active_formatting_elements();
					$this->insert_element( $this->current_token );
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
						$this->adoption_agency_algorithm( $this->current_token );
					}

					$this->reconstruct_active_formatting_elements();
					$node = $this->insert_element( $this->current_token );
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
					$node = $this->insert_element( $this->current_token );
					$this->push_active_formatting_element( $node );
					break;
				case 'NOBR':
					$this->reconstruct_active_formatting_elements();
					if ( $this->is_element_in_scope( 'NOBR' ) ) {
						$this->parse_error();
						$this->adoption_agency_algorithm( $this->current_token );
						$this->reconstruct_active_formatting_elements();
					}
					$node = $this->insert_element( $this->current_token );
					$this->push_active_formatting_element( $node );
					break;
				case 'APPLET':
				case 'MARQUEE':
				case 'OBJECT':
					$this->reconstruct_active_formatting_elements();
					$this->insert_element( $this->current_token );
					$this->active_formatting_elements[] = $this->MARKER;
					break;
				case 'TABLE':
					$this->insert_element( $this->current_token );
					break;

				// Void elements.
				// Some require reconstructing the active formatting elements.
				case 'AREA':
				case 'BR':
				case 'EMBED':
				case 'IMG':
				case 'KEYGEN':
				case 'WBR':
					$this->reconstruct_active_formatting_elements();
				// But others don't.
				case 'META':
				case 'LINK':
				case 'BASE':
				case 'COL':
				case 'FRAME':
				case 'INPUT':
				case 'PARAM':
				case 'SOURCE':
				case 'TRACK':
					$this->insert_element( $this->current_token );
					$this->pop_open_element( false );
					break;
				case 'HR':
					if ( $this->is_element_in_button_scope( 'P' ) ) {
						$this->close_p_element();
					}
					$this->insert_element( $this->current_token );
					$this->pop_open_element( false );
					break;
				case 'TEXTAREA':
					$this->insert_element( $this->current_token );
					break;
				case 'SELECT':
					$this->reconstruct_active_formatting_elements();
					$this->insert_element( $this->current_token );
					break;
				case 'OPTION':
					$this->pop_open_element(false);
				case 'OPTGROUP':
					$this->reconstruct_active_formatting_elements();
					$this->insert_element( $this->current_token );
					break;
				case 'RB':
				case 'RTC':
					if ( $this->is_element_in_scope( 'RB' ) || $this->is_element_in_scope( 'RTC' ) ) {
						$this->parse_error();
						$this->adoption_agency_algorithm( $this->current_token );
						$this->reconstruct_active_formatting_elements();
					}
					$this->insert_element( $this->current_token );
					break;
				case 'RP':
				case 'RT':
					if ( $this->is_element_in_scope( 'RP' ) || $this->is_element_in_scope( 'RT' ) ) {
						$this->parse_error();
						$this->adoption_agency_algorithm( $this->current_token );
						$this->reconstruct_active_formatting_elements();
					}
					$this->insert_element( $this->current_token );
					break;

				// case 'XMP':
				// case 'IFRAME':
				// case 'NOEMBED':
				// case 'MATH':
				// case 'SVG':
				// case 'NOSCRIPT':
				// case 'PLAINTEXT':
				// case 'IMAGE':
				// 	throw new Exception( $this->current_token->tag . ' not implemented yet' );

				default:
					$this->reconstruct_active_formatting_elements();
					$this->insert_element( $this->current_token );
					break;
			}
		} else {
			dbg( "Found {$this->current_token->tag} tag closer" );
			switch ( $this->current_token->tag ) {
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
					if ( ! $this->is_element_in_scope( $this->current_token->tag ) ) {
						$this->parse_error();
						$this->drop_current_tag_token();
						return true;
					}
					$this->generate_implied_end_tags();
					$this->pop_until_tag( $this->current_token->tag, false );
					break;
				case 'FORM':
					$this->generate_implied_end_tags();
					$this->pop_until_tag( $this->current_token->tag, false );
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
					$this->close_p_element(false);
					break;
				case 'LI':
					if ( ! $this->is_element_in_list_item_scope( 'LI' ) ) {
						$this->parse_error();
						$this->drop_current_tag_token();
						return true;
					}
					$this->generate_implied_end_tags(
						array(
							'except_for' => array( 'LI' ),
						)
					);
					$this->pop_until_tag( 'LI', false );
					break;
				case 'DD':
				case 'DT':
					if ( ! $this->is_element_in_scope( $this->current_token->tag ) ) {
						$this->parse_error();
						$this->drop_current_tag_token();
						return true;
					}
					$this->generate_implied_end_tags();
					$this->pop_until_tag( $this->current_token->tag, false );
					break;
				case 'H1':
				case 'H2':
				case 'H3':
				case 'H4':
				case 'H5':
				case 'H6':
					if ( ! $this->is_element_in_scope( array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ) ) ) {
						$this->parse_error();
						$this->drop_current_tag_token();
						return true;
					}
					$this->generate_implied_end_tags();
					$this->pop_until_tag( array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ), false );
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
					dbg( "Found {$this->current_token->tag} tag closer" );
					$this->adoption_agency_algorithm( $this->current_token );
					break;

				case 'APPLET':
				case 'MARQUEE':
				case 'OBJECT':
					if ( ! $this->is_element_in_scope( $this->current_token->tag ) ) {
						$this->parse_error();
						$this->drop_current_tag_token();
						return true;
					}
					$this->generate_implied_end_tags();
					if ( $this->current_node()->tag !== $this->current_token->tag ) {
						$this->parse_error();
					}
					$this->pop_until_tag( $this->current_token->tag, false );
					$this->clear_active_formatting_elements_up_to_last_marker();
					break;

				/*
				 * @divergence from spec:
				 * Close all the open tags when a table-related
				 * tag closer is encountered
				 */
				case 'TBODY':
				case 'TFOOT':
				case 'THEAD':
				case 'TD':
				case 'TH':
				case 'TR':
				case 'TABLE':
					$this->pop_until_tag( $this->current_token->tag, false );
					break;

				case 'BR':
					// This should never happen since Tag_Processor corrects that
				default:
					$this->process_any_other_end_tag( $this->current_token );
					break;
			}
		}
		return true;
	}

	private function process_text($text_start, $text_end) {
		if ($text_start < $text_end) {
			$this->current_token = substr($this->html, $text_start, $text_end - $text_start);
			$this->current_token_start = $text_start;
			$this->current_token_end = $text_end;
			dbg( "Found text node '$this->current_token'" );
			dbg( "Appending text to reconstructed HTML", 1 );
			$this->reconstruct_active_formatting_elements();
		}
	}

	private function process_any_other_end_tag( WP_HTML_Tag_Token $token ) {
		$node = $this->current_node();
		$tag = $token->tag;
		$i = count( $this->open_elements ) - 1;
		while ( true ) {
			if ( $node->tag === $tag ) {
				$this->generate_implied_end_tags(
					array(
						'except_for' => array( $tag ),
					)
				);
				if ( $node->tag !== $tag ) {
					$this->parse_error();
				}
				$this->pop_until_node( $node );
				break;
			} elseif ( $this->is_special_element( $node->tag ) ) {
				$this->parse_error();
				return $this->drop_current_tag_token();
			} else {
				--$i;
				$node = $this->open_elements[ $i ];
			}
		}
	}

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
				return $this->process_any_other_end_tag( $token );
			}
			dbg("AAA: Formatting element = {$formatting_element->tag}", 2);

			// If formatting element is not in the stack of open elements, then this is
			// a parse error; remove the element from the list, and return.
			if ( ! in_array( $formatting_element, $this->open_elements, true ) ) {
				array_splice( $this->active_formatting_elements, $formatting_element_idx, 1 );
				$this->parse_error();
				dbg("Skipping AAA: formatting element is not in the stack of open elements", 2);

				/**
				 * This is not in the spec, but it's necessary.
				 * 
				 * If we were building a DOM, moving on without 
				 * creating a Node would be the same as dropping 
				 * the unexpected token.
				 * 
				 * We're processing a text stream, though, so simply
				 * moving on would leave that token in place. Instead,
				 * we need to drop it explicitly.
				 */
				$this->drop_current_tag_token();
				return;
			}

			// If formatting element is not in scope, then this is a parse error; return
			if ( ! $this->is_element_in_scope( $formatting_element ) ) {
				$this->parse_error();
				dbg("Skipping AAA: formatting element {$formatting_element->tag} is not in scope", 2);
				
				/**
				 * This is not in the spec, but it's necessary.
				 * See the previous "if" statement for details.
				 */
				$this->drop_current_tag_token();
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
				$this->pop_until_node( $formatting_element, false );
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
		if($token !== $this->current_token) {
			// Aesthetic choice for now.
			// @TODO: discuss it with the team
			$tag = strtolower($token->tag);

			$this->add_lexical_update(
				new WP_HTML_Text_Replacement(
					$this->current_token_start,
					$this->current_token_start,
					"<{$tag}>"
				)
			);
		}
		array_push($this->open_elements, $token);
		return $token;
	}

	private function parse_error() {
		// Noop for now
	}

	private function pop_until_tag( $tag_names, $insert_tag_closer_for_last_popped_element = true ) {
		// @TODO split this into two methods
		if(!is_array($tag_names)) {
			$tag_names = array($tag_names);
		}
		while( true ) {
			$popped = $this->pop_open_element( false );
			if(in_array($popped->tag, $tag_names, true)) {
				break;
			}
			$this->insert_tag_closer_before_current_token($popped->tag);
		}
		if($insert_tag_closer_for_last_popped_element) {
			$this->insert_tag_closer_before_current_token($popped->tag);
		}
	}

	private function pop_until_node( WP_HTML_Tag_Token $target, $insert_tag_closer_for_last_popped_element = true ) {
		while( true ) {
			$popped = $this->pop_open_element( false );
			if($popped === $target) {
				break;
			}
			$this->insert_tag_closer_before_current_token($popped->tag);
		}
		if($insert_tag_closer_for_last_popped_element) {
			$this->insert_tag_closer_before_current_token($popped->tag);
		}
	}

	private function pop_open_element($add_close_tag = true) {
		$popped = array_pop( $this->open_elements );
		if ( $add_close_tag ) {
			$this->insert_tag_closer_before_current_token( $popped->tag );
		}
		return $popped;
	}

	private function drop_current_tag_token() {
		$this->add_lexical_update(
			new WP_HTML_Text_Replacement(
				$this->current_token_start,
				$this->current_token_end + 1,
				''
			)
		);
		return true;
	}

	private function insert_tag_closer_before_current_token( $tag ) {
		// Aesthetic choice for now.
		// @TODO: consider preserving the case of the opening tag
		$this->add_lexical_update(
			new WP_HTML_Text_Replacement(
				$this->current_token_start,
				$this->current_token_start,
				"</".strtolower($tag).">"
			)
		);
		$last_afe = end($this->active_formatting_elements);
		if($last_afe && $tag === $last_afe->tag) {
			array_pop($this->active_formatting_elements);
		}
	}

	private function generate_implied_end_tags( $options = null ) {
		while( $this->should_generate_implied_end_tags( $options ) ) {
			$this->pop_open_element( true );
		}
	}

	private function current_node() {
		return end( $this->open_elements );
	}

	private function close_p_element($insert_p_tag_closer = true) {
		dbg( "close_p_element" );
		$this->generate_implied_end_tags(
			array(
				'except_for' => array( 'P' ),
			)
		);
		// If the current node is not a p element, then this is a parse error.
		if ( $this->current_node()->tag !== 'P' ) {
			$this->parse_error();
		}
		$this->pop_until_tag( 'P', false );
		if($insert_p_tag_closer) {
			$this->insert_tag_closer_before_current_token( 'P' );
		}
	}

	private function should_generate_implied_end_tags( $options = null ) {
		$current_tag_name = $this->current_node()->tag;
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

		$thoroughly = true; //null !== $options && isset( $options['thoroughly'] ) && $options['thoroughly'];
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

	private function reconstruct_active_formatting_elements() {
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

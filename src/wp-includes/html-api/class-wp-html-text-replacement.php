<?php
/**
 * HTML Tag Processor: Text replacement class.
 *
 * @package WordPress
 * @subpackage HTML-API
 * @since 6.2.0
 */

/**
 * Data structure used to replace existing content from start to end that allows to drastically improve performance.
 *
 * This class is for internal usage of the WP_HTML_Tag_Processor class.
 *
 * @access private
 * @since 6.2.0
 *
 * @see WP_HTML_Tag_Processor
 */
class WP_HTML_Text_Replacement {
	/**
	 * Byte offset into document where replacement span begins.
	 *
	 * @since 6.2.0
	 * @var int
	 */
	public $start;

	/**
	 * Byte offset into document where replacement span ends.
	 *
	 * @since 6.2.0
	 * @var int
	 */
	public $end;

	/**
	 * Span of text to insert in document to replace existing content from start to end.
	 *
	 * @since 6.2.0
	 * @var string
	 */
	public $text;

	/**
	 * Order in which the replacement was enqueued.
	 * 
	 * @var mixed
	 */
	public $order;

	/**
	 * Constructor.
	 *
	 * @since 6.2.0
	 *
	 * @param int    $start Byte offset into document where replacement span begins.
	 * @param int    $end   Byte offset into document where replacement span ends.
	 * @param string $text  Span of text to insert in document to replace existing content from start to end.
	 * @param string $order Order in which the replacement was enqueued.
	 */
	public function __construct( $start, $end, $text, $order = 0 ) {
		$this->start = $start;
		$this->end   = $end;
		$this->text  = $text;
		$this->order = $order;
	}
}

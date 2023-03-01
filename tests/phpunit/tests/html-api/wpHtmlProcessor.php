<?php
/**
 * Unit tests covering WP_HTML_Processor functionality.
 *
 * @package gutenberg
 */

/**
 * Unit tests for the mobile block editor settings.
 *
 * @covers WP_HTML_Processor
 */

class Tests_HtmlApi_wpHtmlProcessor extends WP_UnitTestCase
{

	public function test_starts()
	{
		$p = new WP_HTML_Processor('<p>Lorem Ipsum Dolor Sit Amet</p>');
		$this->assertEquals(
			'<p>Lorem Ipsum Dolor Sit Amet</p>',
			$p->get_updated_html()
		);
	}

	// public function test_next_tag_throws()
	// {
	// 	$this->expectException(LogicException::class);
	// 	$p = new WP_HTML_Processor('<p>Lorem Ipsum Dolor Sit Amet</p>');
	// 	$p->next_tag();
	// }

	public function test_next_node()
	{
		$p = new WP_HTML_Processor('<p>Lorem <b></b> Ipsum</p><div>');
		$this->assertTrue($p->next_node());
		$this->assertEquals( 'P', $p->get_tag() );

		$this->assertTrue($p->next_node());
		$this->assertEquals( 'B', $p->get_tag() );

		$this->assertTrue($p->next_node());
		$this->assertEquals( 'DIV', $p->get_tag() );

		$this->assertFalse($p->next_node());
	}

	public function test_next_sibling_normative_markup()
	{
		$p = new WP_HTML_Processor('<p>Lorem <b></b> Ipsum</p><div></div>');
		$this->assertTrue($p->next_node());
		$this->assertEquals( 'P', $p->get_tag() );

		$this->assertTrue($p->next_sibling());
		$this->assertEquals( 'DIV', $p->get_tag() );

		$this->assertFalse($p->next_sibling());
	}

	public function test_next_sibling_non_normative_markup()
	{
		$p = new WP_HTML_Processor('<ul><li>1<li>2</ul><div></div>');
		$p->next_node();
		$p->next_node();
		$this->assertEquals( 'LI', $p->get_tag() );

		$this->assertTrue($p->next_sibling());
		$this->assertEquals( 'LI', $p->get_tag() );

		$this->assertFalse($p->next_sibling());
	}

	public function test_nth_child()
	{
		$p = new WP_HTML_Processor('<ul><li>1<li class="last">2</ul>');
		$p->next_node();
		$p->nth_child(2);
		$this->assertEquals( 'LI', $p->get_tag() );
		$this->assertEquals( 'last', $p->get_attribute('class') );
	}

	public function test_get_inner_html()
	{
		$p = new WP_HTML_Processor('<ul><li>1<li>2<li>3</ul>');
		$p->next_node();
		$p->nth_child(2);
		$this->assertEquals( '2', $p->inner_html() );
		// We're supposed to get the same result twice
		// Confirm the processor has rewinded the pointer:
		$this->assertEquals( '2', $p->inner_html() );
	}

	public function test_get_outer_html()
	{
		$p = new WP_HTML_Processor('<ul><li>1<li>2<li>3</ul>');
		$p->next_node();
		$p->nth_child(2);
		$this->assertEquals( '<li>2</li>', $p->outer_html() );
		// We're supposed to get the same result twice
		// Confirm the processor has rewinded the pointer:
		$this->assertEquals( '<li>2</li>', $p->outer_html() );
	}

	public function test_set_inner_html()
	{
		$p = new WP_HTML_Processor('<ul><li>1<li>2<li>3</ul>');
		$p->next_node();
		$p->nth_child(2);
		$p->inner_html('<b><p>99</p></b>');
		$this->assertEquals( '<b><p>99</p></b>', $p->inner_html() );
	}

	public function test_set_outer_html()
	{
		$p = new WP_HTML_Processor('<ul><li>1<li>2<li>3</ul>');
		$p->next_node();
		$p->nth_child(2);
		$p->outer_html('<strong><p>99</p></strong>');
		$this->assertEquals( '<ul><li>1</li><strong><p>99</p></strong><li>3</ul>', $p->get_updated_html() );
		$this->assertEquals( '<strong><p>99</p></strong>', $p->outer_html() );
		$this->assertEquals( '<ul><li>1</li><strong><p>99</p></strong><li>3</ul>', $p->get_updated_html() );
		$this->assertEquals( '<ul><li>1</li><strong><p>99</p></strong><li>3</ul>', $p->get_updated_html() );
	}

}
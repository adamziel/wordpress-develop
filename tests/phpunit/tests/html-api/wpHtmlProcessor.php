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

	public function test_closes_tags()
	{
		$p = new WP_HTML_Processor('<ul><li><li></ul>');
		$p->next_node();
		$p->next_node();
		$p->next_node();
		$p->next_node();
		$this->assertEquals(
			'<ul><li></li><li></li></ul>',
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
		$this->assertEquals( '<strong><p>99</p></strong>', $p->outer_html() );
		// We're supposed to get the same result twice
		// Confirm the processor has rewinded the pointer:
		$this->assertEquals( '<strong><p>99</p></strong>', $p->outer_html() );
		$this->assertEquals( '<ul><li>1</li><strong><p>99</p></strong><li>3</ul>', $p->get_updated_html() );
	}

	public function test_complex_markup()
	{
		$p = new WP_HTML_Processor(<<<'HTML'
	<header>
		<h1>My Site</h1>
		<nav>
			<ul>
				<li><a href="/">Home</a></li>
				<li><a href="/about">About</a></li>
				<li id="third"><a href="/contact">Contact</a></li>
			</ul>
		</nav>
	</header>
	<main>
		<article>
			<h2>My Article</h2>
			<p>
				<b>Lorem ipsum <i>dolor sit <s>amet</b>, consectetur</i> adipiscing elit.</s>
				Quisque euismod, <strong>nisl</strong> nec ultricies ultricies, nunc nisl
				fermentum nunc, eget aliquam massa nisl eget nunc.
			</p>
			<br/><br/>
			<summary>Some summary</summary>
			<img src="http://example.com/image.jpg" alt="Image" />
		</article>
		<hr />
		<section title="definitions">
			<h3>Definitions</h3>
			<p>Here are the definitions for this page:</p>
			<dl>
				<dt>Definition 1
				<dd>Definition 1 text
				<dt>Definition 2
				<dd>Definition 2 text
				<dt>Definition 3
				<dd>Definition 3 text
			</dl>
		</section>
		<section title="data">
			<h3>Data</h3>
			<p>Here is the data for this page:</p>
			<table>
				<tr>
					<th>Column 1</th>
					<th>Column 2</th>
					<th>Column 3</th>
				</tr>
				<tr>
					<td>Row 1, Column 1</td>
					<td>Row 1, Column 2</td>
					<td>Row 1, Column 3</td>
				</tr>
				<tr>
					<td>Row 2, Column 1</td>
					<td>Row 2, Column 2</td>
					<td>Row 2, Column 3</td>
				</tr>
			</table>
		</section>
		<section title="address">
			<p>Contact the author of this page:</p>

			<address>
				<a href="mailto:jim@rock.com">jim@rock.com</a><br>
				<a href="tel:+13115552368">(311) 555-2368</a>
			</address>
		</section>
		<section title="comments">
			<h3>Comments</h3>
			<p>Here are the comments for this page:</p>
			<ul>
				<li>Comment 1
				<li>Comment 2
				<li>Comment 3
			</ul>
			<h4>Leave a comment</h4>
			<form>
				<label for="name">Name</label>
				<input type="text" name="name" id="name" />
				<label for="source">Where did you hear about us?</label>
				<!-- need to support "select" insertion mode first -->
				<!-- <select name="source" id="source">
					<option value="google">Google</option>
					<option value="facebook">Facebook</option>
					<option value="twitter">Twitter</option>
				</select> -->
				<label for="email">Email</label>
				<input type="email" name="email" id="email" />
				<label for="comment">Comment</label>
				<textarea name="comment" id="comment"></textarea>
				<input type="submit" value="Submit" />
			</form>
		</section>
		<footer>
			<p>© 2017 My Site</p>
		</footer>
	</main>
HTML);
		$this->assertTrue($p->next_node());
		$this->assertTrue($p->next_node());
		$this->assertEquals('H1', $p->get_tag());
		$this->assertEquals('My Site', $p->inner_html());

		$this->assertTrue($p->next_node());
		$this->assertEquals('NAV', $p->get_tag());

		$this->assertTrue($p->next_node());
		$this->assertEquals('UL', $p->get_tag());

		$this->assertTrue($p->nth_child(3));
		$this->assertEquals('LI', $p->get_tag());
		$this->assertEquals('third', $p->get_attribute('id'));
		$this->assertEquals('<li id="third"><a href="/contact">Contact</a></li>', $p->outer_html());

		$this->assertTrue($p->next_node());
		$this->assertTrue($p->next_node());
		$this->assertEquals('MAIN', $p->get_tag());

		$this->assertTrue($p->first_child());
		$this->assertEquals('ARTICLE', $p->get_tag());

		$this->assertTrue($p->next_sibling());
		$this->assertEquals('HR', $p->get_tag());

		$this->assertTrue($p->next_sibling());
		$this->assertEquals('SECTION', $p->get_tag());

		$this->assertTrue($p->nth_child(3));
		$this->assertEquals('DL', $p->get_tag());

		$this->assertTrue($p->nth_child(3));
		$this->assertEquals('DT', $p->get_tag());
		$this->assertEquals('Definition 2', $p->inner_html());
		$p->outer_html('<dd>DD</dd>');
		$this->assertEquals('<dd>DD</dd>', $p->outer_html());

		$p->next_node();
		$p->next_node();
		$p->next_node();
		$p->next_node();
		$this->assertEquals('SECTION', $p->get_tag());
		$this->assertEquals('data', $p->get_attribute('title'));

		$this->assertTrue($p->next_sibling());
		$this->assertEquals('SECTION', $p->get_tag());
		$this->assertEquals('address', $p->get_attribute('title'));
		$p->outer_html('');

		$this->assertEquals('SECTION', $p->get_tag());
		$this->assertEquals('comments', $p->get_attribute('title'));

		$this->assertTrue($p->next_sibling());
		$this->assertEquals('FOOTER', $p->get_tag());
		// echo($p->get_updated_html());
	}

	public function test_complex_use_case()
	{
		$p = new WP_HTML_Processor(<<<'HTML'
	<section>
		<p>Text
		<h4 id=presentational-markup>
			<span class=secno>1.11.1</span> 
			Presentational markup
			<a href=#presentational-markup class=self-link>Link</a>
			<p>Text
			<p>Text
		<h3>Another header
HTML);
		$p->next_node();
		$p->nth_child(2);
		$p->outer_html('<img />');
		$this->assertEquals('IMG', $p->get_tag());
		$this->assertEquals('	<section>
		<p>Text
		</p><img /><h3>Another header', $p->get_updated_html());
	}

}
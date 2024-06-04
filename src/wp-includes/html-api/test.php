<?php

require __DIR__ . "/class-wp-html-token.php";
require __DIR__ . "/class-wp-html-span.php";
require __DIR__ . "/class-wp-html-text-replacement.php";
require __DIR__ . "/class-wp-html-decoder.php";
require __DIR__ . "/class-wp-html-attribute-token.php";
require __DIR__ . "/class-wp-xml-decoder.php";
require __DIR__ . "/class-wp-xml-tag-processor.php";
require __DIR__ . "/class-wp-xml-processor.php";

// var_dump(
//     WP_XML_Decoder::decode('&#x93;&#x93;&#x93;') // The &amp; &lt; &gt; &quot; &apos; &#x1f170; &#x000000FFFD; &#x93;&#x1f604;&#x94;')
// );

// die();
// $processor = new WP_XML_Processor(
//     '<root><wp:post>The &amp; &lt; &gt; &quot; &apos; &#x1f170; &#x000000FFFD; &#x93;&#x1f604;&#x94; </wp:post></root>'
//     // '<root><wp:post>The open source publishing  <content> platform of choice for millions of websites <image /> worldwide—from creators </content>and small businesses</wp:post></root>'
// );
// // $processor->declare_element_as_pcdata('wp:post');
// $processor->next_tag('wp:post');
// var_dump($processor->get_modifiable_text());
// var_dump($processor->get_inner_text());
// var_dump(phpversion());
// $xml = '<wp:content wp:post-type="x" disabled="yes">';
// $expected_match = '<wp:content wp:post-type="x" disabled="yes">';
// $match_nth_token = 1;
// $processor = new class( $xml ) extends WP_XML_Tag_Processor {
//     /**
//      * Returns the raw span of XML for the currently-matched
//      * token, or null if not paused on any token.
//      *
//      * @return string|null Raw XML content of currently-matched token,
//      *                     otherwise `null` if not matched.
//      */
//     public function get_raw_token() {
//         if (
//             WP_XML_Tag_Processor::STATE_READY === $this->parser_state ||
//             WP_XML_Tag_Processor::STATE_INCOMPLETE_INPUT === $this->parser_state ||
//             WP_XML_Tag_Processor::STATE_COMPLETE === $this->parser_state
//         ) {
//             return null;
//         }

//         $this->set_bookmark( 'mark' );
//         $mark = $this->bookmarks['mark'];

//         return substr( $this->xml, $mark->start, $mark->length );
//     }
// };

// for ( $i = 0; $i < $match_nth_token; $i++ ) {
//     $processor->next_token();
// }

// $raw_token = $processor->get_raw_token();
// var_dump($raw_token);
// var_dump($expected_match);
// die();

// $i = 0;
// while( $processor->next_tag(array()) ) {
//     echo "\n " . dump_token($processor);
//     echo $processor->get_inner_text();
// }
// die();



function chunk_text($text) {
    $length = strlen($text);
    $chunks_nb = ceil($length / 1000);
    for( $i = 0; $i < $chunks_nb; $i++ ) {
        yield substr($text, $i * 1000, 1000);
    }
}

function stream_next_xml_token( $get_next_chunk ) {
    $streamed_data = $get_next_chunk->current();
    $get_next_chunk->next();

    $breadcrumbs = array();
    $parser_context = WP_XML_Processor::IN_PROLOG_CONTEXT;
    $processor = new WP_XML_Processor( $streamed_data, $breadcrumbs, $parser_context );
    while(true) {
        $token_found = $processor->next_token();

        if ($processor->paused_at_incomplete_token()) {
            $processor = new WP_XML_Processor(
                // ' ' is a hack to avoid treating repeated <?xml as xml declaration
                ' ' . $processor->get_unparsed_xml() . $get_next_chunk->current(),
                $processor->get_breadcrumbs(),
                $processor->get_parser_context()
            );
            $get_next_chunk->next();
            continue;
        } else if($processor->get_last_error() !== null) {
            echo "\n ERROR: ";
            var_dump($processor->get_last_error());
            return false;
        } else if (!$token_found) {
            // finished
            return true;
        }
        yield $processor;
    }
}


$wxr = file_get_contents(__DIR__ . '/test.wxr');
$tokens = stream_next_xml_token(chunk_text($wxr));
foreach($tokens as $processor) {
    if ($processor->get_token_type() === '#cdata-section' && $processor->matches_breadcrumbs(array('content:encoded'))) {
        echo "\n " . dump_token($processor);
    }
    // echo "\n " . dump_token($processor);
}

function dump_token(WP_XML_Processor $p) {
    $result = $p->get_token_type() . ' ';
    switch($p->get_token_type()) {
        case '#tag':
            $result .= '(' . $p->get_token_name() . ')' . ' IN ' . implode( ' > ', $p->get_breadcrumbs() );
            break;
        case '#text':
        case '#cdata-section':
            $result .= '(' . preg_replace('~\s+~', ' ', $p->get_modifiable_text()) . ')';
            break;
    }
    return $result;
}
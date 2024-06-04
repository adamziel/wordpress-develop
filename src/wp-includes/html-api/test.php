<?php

require __DIR__ . "/class-wp-html-token.php";
require __DIR__ . "/class-wp-html-span.php";
require __DIR__ . "/class-wp-html-text-replacement.php";
require __DIR__ . "/class-wp-html-decoder.php";
require __DIR__ . "/class-wp-html-attribute-token.php";
require __DIR__ . "/class-wp-xml-decoder.php";
require __DIR__ . "/class-wp-xml-tag-processor.php";
require __DIR__ . "/class-wp-xml-processor.php";


$wxr = file_get_contents(__DIR__ . '/test.wxr');
$tokens = stream_next_xml_token(chunk_text($wxr));
foreach($tokens as $processor) {
    if ($processor->get_token_type() === '#cdata-section' && $processor->matches_breadcrumbs(array('content:encoded'))) {
        echo "\n " . dump_token($processor);
    }
}

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
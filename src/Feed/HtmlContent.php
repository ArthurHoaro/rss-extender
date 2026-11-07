<?php

namespace ArthurHoaro\RssExtender\Feed;

use DOMDocument;
use DOMElement;
use FeedIo\Feed\ItemInterface;
use FeedIo\Feed\NodeInterface;
use FeedIo\Rule\Atom\Content;

/**
 * feed-io 6.5 writes Atom content without type="html", so readers show the markup as plain text.
 */
class HtmlContent extends Content
{
    protected function addElement(
        DOMDocument $document,
        DOMElement $rootElement,
        NodeInterface $node,
    ): void {
        if ($node instanceof ItemInterface) {
            $element = $this->generateElement($document, $node->getContent());
            $element->setAttribute('type', 'html');
            $rootElement->appendChild($element);
        }
    }
}

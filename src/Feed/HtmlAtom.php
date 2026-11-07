<?php

namespace ArthurHoaro\RssExtender\Feed;

use FeedIo\RuleSet;
use FeedIo\Standard\Atom;

class HtmlAtom extends Atom
{
    public function buildItemRuleSet(): RuleSet
    {
        return parent::buildItemRuleSet()->add(new HtmlContent());
    }
}

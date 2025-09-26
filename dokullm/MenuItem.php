<?php

namespace dokuwiki\plugin\dokullm;

use dokuwiki\Menu\Item\AbstractItem;

/**
 * Class MenuItem
 *
 * Implements the Copy page button for DokuWiki's menu system
 *
 * @package dokuwiki\plugin\dokullm
 */
class MenuItem extends AbstractItem
{

    /** @var string do action for this plugin */
    protected $type = 'dokullmplugin__copy';

    /** @var string icon file */
    protected $svg = __DIR__ . '/copy.svg';

    /**
     * Get label from plugin language file
     *
     * @return string
     */
    public function getLabel()
    {
        $hlp = plugin_load('action', 'dokullm');
        return $hlp->getLang('copy_page_button');
    }
}

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
    protected $svg = __DIR__ . '/images/copy.svg';

    /**
     * Get the label for the menu item from the plugin language file
     *
     * This method loads the dokullm action plugin and retrieves the 
     * localized label for the copy page button from the language files.
     * The label is defined in the lang/en/lang.php file as 'copy_page_button'.
     *
     * @return string The localized label for the menu item
     */
    public function getLabel()
    {
        // Load the action plugin to access its language strings
        $hlp = plugin_load('action', 'dokullm');
        // Return the localized label for the copy page button
        return $hlp->getLang('copy_page_button');
    }
}

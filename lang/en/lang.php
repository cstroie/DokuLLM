<?php
/**
 * English language file for dokullm plugin
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Costin Stroie <costinstroie@eridu.eu.org>
 */

/**
 * Button label for the copy page functionality
 * 
 * This string is used as the label for the copy page button that appears
 * in the page tools menu. It should be a clear, actionable phrase that
 * indicates the button's purpose.
 */
$lang['copy_page_button'] = "Copy page";

/**
 * JavaScript prompt message for entering a new page ID
 * 
 * This message is displayed in a JavaScript prompt dialog when the user
 * clicks the copy page button. It asks the user to enter the ID for the
 * new page that will be created as a copy of the current page.
 */
$lang['js']['enter_page_id'] = "Enter new-page's ID.";

/**
 * JavaScript error message for ID validation
 * 
 * This message is displayed in a JavaScript alert dialog when the user
 * enters the same ID as the current page. It enforces that the new page
 * must have a different ID from the source page.
 */
$lang['js']['different_id_required'] = 'You must enter a different ID from current page.';

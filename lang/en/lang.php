<?php
/**
 * English language file for DokuLLM plugin
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
$lang['js']['enter_page_id'] = "Enter new-page's ID: ";

/**
 * JavaScript error message for ID validation
 * 
 * This message is displayed in a JavaScript alert dialog when the user
 * enters the same ID as the current page. It enforces that the new page
 * must have a different ID from the source page.
 */
$lang['js']['different_id_required'] = 'You must enter a different ID from current page.';

$lang['js']['insert_template'] = 'Insert Template';
$lang['js']['find_template'] = 'Find Template';
$lang['js']['loading_actions'] = 'Loading DokuLLM actions...';
$lang['js']['custom_prompt_placeholder'] = 'Enter your prompt...';
$lang['js']['send'] = 'Send';
$lang['js']['error_loading_dokullm'] = 'DokuLLM profiles page not found. Please check the "dokullm:" namespace.';
$lang['js']['no_text_provided'] = 'Please select text or enter content to process';
$lang['js']['processing'] = 'Processing...';
$lang['js']['searching'] = 'Searching...';
$lang['js']['backend_error'] = 'Network response was not ok: ';
$lang['js']['thinking_process'] = 'AI Thinking Process';
$lang['js']['close'] = 'Close';
$lang['js']['close_title'] = 'Close Modal';
$lang['js']['append'] = 'Append';
$lang['js']['append_title'] = 'Append to Report';
$lang['js']['no_prompt_provided'] = 'Please enter a prompt';
$lang['js']['no_text_provided'] = 'Please select text or enter content to process';
$lang['js']['template_found'] = 'Template found and inserted: ';
$lang['js']['no_template_found'] = 'No suitable template found for this content.';
$lang['js']['loading_template'] = 'Loading template...';


$lang['template_not_found'] = 'Template not found: ';
$lang['no_text_provided'] = 'No text provided';
$lang['unauthorized'] = 'You are not allowed to read this file: ';
$lang['error_finding_template'] = 'Error finding template: ';
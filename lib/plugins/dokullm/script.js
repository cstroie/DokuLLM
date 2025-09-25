/**
 * JavaScript for LLM Integration Plugin
 * 
 * This script adds LLM processing capabilities to DokuWiki's edit interface.
 * It creates a toolbar with buttons for various text processing operations
 * and handles the communication with the backend plugin.
 */

(function() {
    'use strict';
    
    /**
     * Initialize the plugin when the DOM is ready
     * 
     * Checks if we're on an edit page and adds the LLM tools if so.
     */
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DokuLLM: DOM loaded, initializing plugin');
        // Only run on edit pages
        if (!document.getElementById('wiki__text')) {
            console.log('DokuLLM: Not on edit page, exiting');
            return;
        }
        
        // Replace textarea with contenteditable div
        console.log('DokuLLM: Replacing textarea with contenteditable div');
        replaceTextareaWithDiv();
        
        // Add LLM tools to the editor
        console.log('DokuLLM: Adding LLM tools to editor');
        addLLMTools();
        console.log('DokuLLM: Plugin initialization complete');
    });
    
    /**
     * Replace the textarea with a contenteditable div
     * 
     * Creates a contenteditable div with the same content as the textarea
     * and replaces the textarea with it.
     */
    function replaceTextareaWithDiv() {
        const textarea = document.getElementById('wiki__text');
        if (!textarea) {
            console.log('DokuLLM: Textarea not found');
            return;
        }
        
        console.log('DokuLLM: Creating contenteditable div');
        // Create contenteditable div
        const div = document.createElement('div');
        div.id = 'wiki__text_div';
        div.className = 'contenteditable-editor';
        div.contentEditable = true;
        div.textContent = textarea.value;
        
        // Copy textarea attributes to div
        const attributes = ['rows', 'cols', 'tabindex', 'accesskey'];
        attributes.forEach(attr => {
            if (textarea.hasAttribute(attr)) {
                div.setAttribute(attr, textarea.getAttribute(attr));
            }
        });
        
        // Copy styles from textarea to div
        const computedStyle = window.getComputedStyle(textarea);
        div.style.cssText = `
            width: ${computedStyle.width};
            height: ${computedStyle.height};
            min-height: 200px;
            padding: ${computedStyle.paddingTop} ${computedStyle.paddingRight} ${computedStyle.paddingBottom} ${computedStyle.paddingLeft};
            border: ${computedStyle.borderTopWidth} ${computedStyle.borderTopStyle} ${computedStyle.borderTopColor};
            border-radius: ${computedStyle.borderTopLeftRadius};
            font-family: ${computedStyle.fontFamily};
            font-size: ${computedStyle.fontSize};
            line-height: ${computedStyle.lineHeight};
            resize: vertical;
            overflow: auto;
            box-sizing: border-box;
            background-color: ${computedStyle.backgroundColor};
            color: ${computedStyle.color};
        `;
        
        // Hide original textarea and keep it in the DOM
        //textarea.style.display = 'none';
        
        // Insert div after the hidden textarea
        textarea.parentNode.insertBefore(div, textarea.nextSibling);
        
        // Add event listener to sync content back to textarea before form submission
        const form = textarea.closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                console.log('DokuLLM: Syncing content to hidden textarea on form submit');
                // Preserve whitespace and newlines by using innerHTML and converting br tags
                let content = div.innerHTML;
                // Convert <br> tags to newlines
                content = content.replace(/<br\s*\/?>/gi, '\n');
                // Remove HTML tags but preserve content
                content = content.replace(/<[^>]*>/g, '');
                // Convert HTML entities back to characters
                const textArea = document.createElement('textarea');
                textArea.innerHTML = content;
                textarea.value = textArea.value;
            });
        }
        
        // Also sync content when editor loses focus (for better user experience)
        div.addEventListener('blur', function() {
            console.log('DokuLLM: Syncing content to hidden textarea on blur');
            // Preserve whitespace and newlines by using innerHTML and converting br tags
            let content = div.innerHTML;
            // Convert <br> tags to newlines
            content = content.replace(/<br\s*\/?>/gi, '\n');
            // Remove HTML tags but preserve content
            content = content.replace(/<[^>]*>/g, '');
            // Convert HTML entities back to characters
            const textArea = document.createElement('textarea');
            textArea.innerHTML = content;
            textarea.value = textArea.value;
        });
        
        console.log('DokuLLM: Contenteditable div created and inserted');
    }
    
    /**
     * Add the LLM toolbar to the editor interface
     * 
     * Creates a toolbar with buttons for each LLM operation and inserts
     * it before the wiki text editor.
     */
    function addLLMTools() {
        const editor = document.getElementById('wiki__text_div');
        if (!editor) {
            console.log('DokuLLM: Editor div not found');
            return;
        }
        
        console.log('DokuLLM: Creating LLM toolbar');
        // Create toolbar container
        const toolbar = document.createElement('div');
        toolbar.id = 'llm-toolbar';
        toolbar.className = 'toolbar';
        
        // Get metadata to check if template exists
        const metadata = getPageMetadata();
        console.log('DokuLLM: Page metadata retrieved', metadata);
        
        // Add template button if template is defined
        if (metadata.template) {
            console.log('DokuLLM: Adding template button for', metadata.template);
            const templateBtn = document.createElement('button');
            templateBtn.type = 'button';
            templateBtn.className = 'toolbutton';
            templateBtn.textContent = 'Insert Template';
            templateBtn.addEventListener('click', () => insertTemplateContent(metadata.template));
            toolbar.appendChild(templateBtn);
        }
        
        // Add buttons
        const buttons = [
            {action: 'complete', label: 'Complete'},
            {action: 'rewrite', label: 'Rewrite'},
            {action: 'grammar', label: 'Grammar'},
            {action: 'summarize', label: 'Summarize'},
            {action: 'conclusion', label: 'Conclusion'},
            {action: 'analyze', label: 'Analyze'},
            {action: 'continue', label: 'Continue'}
        ];
        
        buttons.forEach(button => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'toolbutton';
            btn.textContent = button.label;
            btn.dataset.action = button.action;
            btn.addEventListener('click', () => processText(button.action));
            toolbar.appendChild(btn);
        });
        
        
        // Insert toolbar before the editor
        editor.parentNode.insertBefore(toolbar, editor);
        
        // Add custom prompt input below the editor
        console.log('DokuLLM: Adding custom prompt input below editor');
        const customPromptContainer = document.createElement('div');
        customPromptContainer.className = 'llm-custom-prompt';
        customPromptContainer.id = 'llm-custom-prompt';
        
        const promptInput = document.createElement('input');
        promptInput.type = 'text';
        promptInput.placeholder = 'Enter custom prompt...';
        promptInput.className = 'llm-prompt-input';
        
        const sendButton = document.createElement('button');
        sendButton.type = 'button';
        sendButton.className = 'toolbutton llm-prompt-button';
        sendButton.textContent = 'Send';
        sendButton.addEventListener('click', () => processCustomPrompt(promptInput.value));
        
        customPromptContainer.appendChild(promptInput);
        customPromptContainer.appendChild(sendButton);
        
        // Insert custom prompt container after the editor
        editor.parentNode.insertBefore(customPromptContainer, editor.nextSibling);
        
        // Add CSS styles
        addStyles();
        console.log('DokuLLM: LLM toolbars added successfully');
    }
    
    /**
     * Process text using the specified LLM action
     * 
     * Gets the selected text (or full editor content), sends it to the
     * backend for processing, and replaces the text with the result.
     * 
     * @param {string} action - The action to perform (complete, rewrite, etc.)
     */
    function processText(action) {
        console.log('DokuLLM: Processing text with action:', action);
        const editor = document.getElementById('wiki__text_div');
        const hiddenTextarea = document.getElementById('wiki__text');
        if (!editor || !hiddenTextarea) {
            console.log('DokuLLM: Editor or hidden textarea not found');
            return;
        }
        
        // Get metadata from the page
        const metadata = getPageMetadata();
        console.log('DokuLLM: Retrieved metadata:', metadata);
        
        const selectedText = getSelectedText(editor);
        const fullText = (() => {
            // Preserve whitespace and newlines for processing
            let content = editor.innerHTML;
            content = content.replace(/<br\s*\/?>/gi, '\n');
            content = content.replace(/<[^>]*>/g, '');
            const textArea = document.createElement('textarea');
            textArea.innerHTML = content;
            return textArea.value;
        })();
        const textToProcess = selectedText || fullText;
        console.log('DokuLLM: Text to process length:', textToProcess.length);
        
        if (!textToProcess.trim()) {
            console.log('DokuLLM: No text to process');
            alert('Please select text or enter content to process');
            return;
        }
        
        // Show loading indicator
        const originalButton = event.target;
        const originalText = originalButton.textContent;
        originalButton.textContent = 'Processing...';
        originalButton.disabled = true;
        console.log('DokuLLM: Button disabled, showing processing state');
        
        // Update hidden textarea with current content
        // Preserve whitespace and newlines
        let content = editor.innerHTML;
        content = content.replace(/<br\s*\/?>/gi, '\n');
        content = content.replace(/<[^>]*>/g, '');
        const textArea = document.createElement('textarea');
        textArea.innerHTML = content;
        hiddenTextarea.value = textArea.value;
        console.log('DokuLLM: Synced content to hidden textarea');
        
        // Get additional prompt if needed (e.g., for translation)
        let targetLanguage = '';
        
        // Send AJAX request
        console.log('DokuLLM: Sending AJAX request to backend');
        const formData = new FormData();
        formData.append('call', 'plugin_dokullm');
        formData.append('action', action);
        formData.append('text', textToProcess);
        formData.append('prompt', targetLanguage);
        formData.append('metadata', JSON.stringify(metadata));
        
        fetch(DOKU_BASE + 'lib/exe/ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('DokuLLM: Received response from backend');
            return response.json();
        })
        .then(data => {
            if (data.error) {
                console.log('DokuLLM: Error from backend:', data.error);
                throw new Error(data.error);
            }
            
            console.log('DokuLLM: Processing successful, result length:', data.result.length);
            // Replace selected text or append to editor
            if (selectedText) {
                console.log('DokuLLM: Replacing selected text');
                replaceSelectedText(editor, data.result);
            } else {
                console.log('DokuLLM: Replacing full text content');
                // Preserve metadata when doing full page update
                const text = (() => {
                    // Preserve whitespace and newlines
                    let content = editor.innerHTML;
                    content = content.replace(/<br\s*\/?>/gi, '\n');
                    content = content.replace(/<[^>]*>/g, '');
                    const textArea = document.createElement('textarea');
                    textArea.innerHTML = content;
                    return textArea.value;
                })();
                const metadataContent = extractMetadata(text);
                // For contenteditable div, we need to properly format the content
                editor.innerHTML = metadataContent + data.result.replace(/\n/g, '<br>');
            }
        })
        .catch(error => {
            console.log('DokuLLM: Error during processing:', error.message);
            alert('Error: ' + error.message);
        })
        .finally(() => {
            console.log('DokuLLM: Resetting button and enabling editor');
            resetButton(originalButton, originalText);
            editor.readOnly = false;
        });
    }
    
    /**
     * Process text with a custom user prompt
     * 
     * @param {string} customPrompt - The user's custom prompt
     */
    function processCustomPrompt(customPrompt) {
        console.log('DokuLLM: Processing custom prompt:', customPrompt);
        if (!customPrompt.trim()) {
            console.log('DokuLLM: No custom prompt provided');
            alert('Please enter a prompt');
            return;
        }
        
        const editor = document.getElementById('wiki__text');
        if (!editor) {
            console.log('DokuLLM: Editor not found for custom prompt');
            return;
        }
        
        const selectedText = getSelectedText(editor);
        const fullText = editor.value;
        const textToProcess = selectedText || fullText;
        console.log('DokuLLM: Text to process length:', textToProcess.length);
        
        if (!textToProcess.trim()) {
            console.log('DokuLLM: No text to process for custom prompt');
            alert('Please select text or enter content to process');
            return;
        }
        
        // Get metadata from the page
        const metadata = getPageMetadata();
        console.log('DokuLLM: Retrieved metadata for custom prompt:', metadata);
        
        // Find the Send button and show loading state
        const toolbar = document.getElementById('llm-custom-prompt');
        const sendButton = toolbar.querySelector('.llm-prompt-button');
        const originalText = sendButton.textContent;
        sendButton.textContent = 'Processing...';
        sendButton.disabled = true;
        console.log('DokuLLM: Send button disabled, showing processing state');
        
        // Make textarea readonly during processing
        editor.readOnly = true;
        
        // Send AJAX request
        console.log('DokuLLM: Sending custom prompt AJAX request to backend');
        const formData = new FormData();
        formData.append('call', 'plugin_dokullm');
        formData.append('action', 'custom');
        formData.append('text', textToProcess);
        formData.append('prompt', customPrompt);
        formData.append('metadata', JSON.stringify(metadata));
        
        fetch(DOKU_BASE + 'lib/exe/ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('DokuLLM: Received response for custom prompt');
            return response.json();
        })
        .then(data => {
            if (data.error) {
                console.log('DokuLLM: Error from backend for custom prompt:', data.error);
                throw new Error(data.error);
            }
            
            console.log('DokuLLM: Custom prompt processing successful, result length:', data.result.length);
            // Replace selected text or append to editor
            if (selectedText) {
                console.log('DokuLLM: Replacing selected text for custom prompt');
                replaceSelectedText(editor, data.result);
            } else {
                console.log('DokuLLM: Replacing full text content for custom prompt');
                // Preserve metadata when doing full page update
                const text = (() => {
                    // Preserve whitespace and newlines
                    let content = editor.innerHTML;
                    content = content.replace(/<br\s*\/?>/gi, '\n');
                    content = content.replace(/<[^>]*>/g, '');
                    const textArea = document.createElement('textarea');
                    textArea.innerHTML = content;
                    return textArea.value;
                })();
                const metadataContent = extractMetadata(text);
                // For contenteditable div, we need to properly format the content
                editor.innerHTML = metadataContent + data.result.replace(/\n/g, '<br>');
            }
            
            // Clear the input field
            const promptInput = toolbar.querySelector('.llm-prompt-input');
            if (promptInput) {
                promptInput.value = '';
            }
        })
        .catch(error => {
            console.log('DokuLLM: Error during custom prompt processing:', error.message);
            alert('Error: ' + error.message);
        })
        .finally(() => {
            console.log('DokuLLM: Resetting send button and enabling editor');
            resetButton(sendButton, originalText);
            editor.readOnly = false;
        });
    }
    
    /**
     * Get the currently selected text in the editor
     * 
     * @param {HTMLElement} editor - The editor element (textarea or div)
     * @returns {string} The selected text
     */
    function getSelectedText(editor) {
        if (editor.tagName === 'TEXTAREA') {
            const start = editor.selectionStart;
            const end = editor.selectionEnd;
            return editor.value.substring(start, end);
        } else {
            // For contenteditable div
            const selection = window.getSelection();
            return selection.toString();
        }
    }
    
    /**
     * Replace the selected text in the editor with new text
     * 
     * @param {HTMLElement} editor - The editor element (textarea or div)
     * @param {string} newText - The new text to insert
     */
    function replaceSelectedText(editor, newText) {
        if (editor.tagName === 'TEXTAREA') {
            const start = editor.selectionStart;
            const end = editor.selectionEnd;
            const text = editor.value;
            
            // If this is a full replacement (no selection), preserve metadata
            if (start === 0 && end === text.length) {
                const metadata = extractMetadata(text);
                editor.value = metadata + newText;
            } else {
                editor.value = text.substring(0, start) + newText + text.substring(end);
                
                // Set cursor position after inserted text
                const newCursorPos = start + newText.length;
                editor.setSelectionRange(newCursorPos, newCursorPos);
            }
            
            editor.focus();
        } else {
            // For contenteditable div
            const selection = window.getSelection();
            if (selection.rangeCount > 0) {
                const range = selection.getRangeAt(0);
                
                // If this is a full replacement, preserve metadata
                const text = editor.textContent;
                if (range.startOffset === 0 && range.endOffset === text.length) {
                    const metadata = extractMetadata(text);
                    editor.textContent = metadata + newText;
                } else {
                    range.deleteContents();
                    range.insertNode(document.createTextNode(newText));
                }
                
                // Collapse selection to end of inserted text
                range.collapse(false);
                selection.removeAllRanges();
                selection.addRange(range);
            } else {
                // No selection, just append
                editor.textContent += newText;
            }
        }
    }
    
    /**
     * Extract metadata directives from the beginning of the text
     * 
     * @param {string} text - The full text content
     * @returns {string} The metadata directives
     */
    function extractMetadata(text) {
        const metadataRegex = /^(~~LLM_[A-Z]+:[^~]+~~\s*)*/;
        const match = text.match(metadataRegex);
        return match ? match[0] : '';
    }
    
    /**
     * Reset a button to its original state
     * 
     * @param {HTMLButtonElement} button - The button to reset
     * @param {string} originalText - The original button text
     */
    function resetButton(button, originalText) {
        button.textContent = originalText;
        button.disabled = false;
    }
    
    /**
     * Get page metadata for LLM context
     * 
     * Extracts template and example page information from page metadata
     * 
     * @returns {Object} Metadata object with template and examples
     */
    function getPageMetadata() {
        const metadata = {
            template: '',
            examples: []
        };
        
        // Look for metadata in the page content
        const pageContent = document.getElementById('wiki__text')?.value || '';
        
        // Extract template page from metadata
        const templateMatch = pageContent.match(/~~LLM_TEMPLATE:([^~]+)~~/);
        if (templateMatch) {
            metadata.template = templateMatch[1].trim();
        }
        
        // Extract example pages from metadata
        const exampleMatches = pageContent.match(/~~LLM_EXAMPLES:([^~]+)~~/);
        if (exampleMatches) {
            metadata.examples = exampleMatches[1].split(',').map(example => example.trim());
        }
        
        return metadata;
    }
    
    /**
     * Insert template content into the editor
     * 
     * Fetches template content from the backend and inserts it into the editor
     * 
     * @param {string} templateId - The template page ID
     */
    function insertTemplateContent(templateId) {
        console.log('DokuLLM: Inserting template content for:', templateId);
        const editor = document.getElementById('wiki__text');
        if (!editor) {
            console.log('DokuLLM: Editor not found for template insertion');
            return;
        }
        
        // Show loading indicator
        const toolbar = document.getElementById('llm-toolbar');
        const originalContent = toolbar.innerHTML;
        toolbar.innerHTML = '<span>Loading template...</span>';
        editor.readOnly = true;
        console.log('DokuLLM: Showing loading indicator for template');
        
        // Send AJAX request to get template content
        console.log('DokuLLM: Sending AJAX request to get template content');
        const formData = new FormData();
        formData.append('call', 'plugin_dokullm');
        formData.append('action', 'get_template');
        formData.append('template', templateId);
        
        fetch(DOKU_BASE + 'lib/exe/ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('DokuLLM: Received template response');
            return response.json();
        })
        .then(data => {
            if (data.error) {
                console.log('DokuLLM: Error retrieving template:', data.error);
                throw new Error(data.error);
            }
            
            console.log('DokuLLM: Template retrieved successfully, content length:', data.result.content.length);
            // Insert template content at cursor position or at the beginning
            const cursorPos = editor.selectionStart;
            const text = editor.value;
            editor.value = text.substring(0, cursorPos) + data.result.content + text.substring(cursorPos);
            
            // Set cursor position after inserted content
            const newCursorPos = cursorPos + data.result.content.length;
            editor.setSelectionRange(newCursorPos, newCursorPos);
            editor.focus();
        })
        .catch(error => {
            console.log('DokuLLM: Error during template insertion:', error.message);
            alert('Error: ' + error.message);
        })
        .finally(() => {
            console.log('DokuLLM: Restoring toolbar and enabling editor');
            toolbar.innerHTML = originalContent;
            editor.readOnly = false;
        });
    }
    
    /**
     * Add CSS styles for the LLM toolbar
     * 
     * Dynamically creates and adds CSS rules for the toolbar and buttons.
     */
    function addStyles() {
        const style = document.createElement('style');
        style.textContent = `
            .contenteditable-editor {
                width: 100%;
                min-height: 200px;
                padding: 5px;
                border: 1px solid #ccc;
                border-radius: 3px;
                font-family: monospace;
                font-size: 14px;
                line-height: 1.4;
                resize: vertical;
                overflow: auto;
                box-sizing: border-box;
                background-color: #fff;
                color: #000;
            }
            
            .llm-button {
                margin-right: 5px;
                margin-bottom: 5px;
                padding: 5px 10px;
                border: none;
                border-radius: 3px;
                cursor: pointer;
            }
            
            .llm-button:disabled {
                cursor: not-allowed;
            }
            
            .llm-custom-prompt {
                margin-bottom: 10px;
                display: flex;
                align-items: center;
            }
            
            .llm-prompt-input {
                flex: 1;
                margin-right: 5px;
                padding: .1em .5em;
                border: 1px solid #ccc;
                border-radius: 3px;
            }
        `;
        document.head.appendChild(style);
    }
})();

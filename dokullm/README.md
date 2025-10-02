# DokuLLM Plugin

A DokuWiki plugin that integrates Large Language Models (LLM) to enhance content creation and editing capabilities.

## Features

This plugin adds a comprehensive toolbar to DokuWiki's edit interface with the following LLM-powered functions:

- **Insert Template**: Automatically insert template content when LLM_TEMPLATE metadata is defined
- **Complete**: Automatically complete partial text
- **Rewrite**: Improve clarity and flow of existing text
- **Grammar**: Correct grammar and spelling errors
- **Summarize**: Create concise summaries of longer texts
- **Conclusion**: Generate well-structured conclusions based on content
- **Analyze**: Perform detailed analysis of text content
- **Continue**: Continue writing from the current text
- **Custom Prompts**: Process text with your own custom instructions

## Metadata Support

The plugin supports metadata tags that can be added to pages to provide context for LLM processing:

- `~~LLM_TEMPLATE:page:id~~` - Specify a template page to use as reference
- `~~LLM_EXAMPLES:page1:id,page2:id~~` - Specify example pages for reference
- `~~LLM_PREVIOUS:page:id~~` - Specify a previous report page for continuity

These metadata tags help the LLM understand the context and style of the content being processed.

Additionally, the plugin automatically retrieves relevant text snippets from ChromaDB to provide context examples when processing content.
```

## Requirements

- DokuWiki installation
- Access to an LLM API (e.g., OpenAI, Ollama, or any compatible service)
- PHP with cURL support

## Installation

1. Clone or download this repository
2. Place the `dokullm` folder in your DokuWiki's `lib/plugins/` directory
3. The plugin should be automatically recognized by DokuWiki

## Configuration

After installation, configure the plugin through DokuWiki's Configuration Manager:

- **API URL**: The endpoint for your LLM service (default: OpenAI's GPT API)
- **API Key**: Your authentication key for the LLM service
- **Model**: The specific model to use (e.g., gpt-3.5-turbo, gpt-4)
- **Timeout**: Maximum time to wait for API responses (in seconds)
- **Language**: Language for prompt templates (default: en)
- **Temperature**: Controls randomness in responses (0.0-1.0)
- **Top-p**: Nucleus sampling parameter (0.0-1.0)
- **Top-k**: Top-k sampling parameter (integer >= 1)
- **Min-p**: Minimum probability threshold (0.0-1.0)

## Usage

1. Navigate to any page in edit mode
2. You'll see a toolbar with LLM action buttons above the text editor
3. Either:
   - Select specific text and click an action button to process only that text
   - Click an action button without selecting text to process the entire content
4. Use the custom prompt input at the bottom to process text with your own instructions

### Using Metadata

To use metadata for better context:

1. Add `~~LLM_TEMPLATE:reports:mri:templates:cerebral-normal~~` at the top of your page to specify a template
2. Add `~~LLM_EXAMPLES:reports:mri:2025:example-report~~` to specify example pages
3. When a template is defined, an "Insert Template" button will appear in the toolbar
4. Click the "Insert Template" button to insert the full template content into your page
5. The LLM will use these references along with automatically retrieved text snippets when processing your content

### Copy Page with Template Support

The plugin also includes a "Copy page" button in the page tools that:
- Preserves LLM_TEMPLATE metadata when copying pages
- Automatically adds LLM_TEMPLATE metadata when copying from pages with "template" in their ID

## How It Works

The plugin works by sending selected text to your configured LLM API with specific prompts for each function. The processed result is then inserted back into the editor based on the LLM_RESULT metadata or default behavior (replace).

When creating content, the plugin automatically:
1. Queries ChromaDB for relevant text snippets to use as examples
2. If no template is specified, queries ChromaDB for an appropriate template
3. Sends all context information (template, examples, and snippets) to the LLM
4. Returns the processed result to the editor

## DokuWiki Pages Structure

The plugin uses a specific namespace structure for its prompt templates and configurations:

- `dokullm:prompts` - Contains all prompt templates used by the plugin
- `dokullm:prompts:en:action` - Contains action-specific prompt templates

## Prompt Actions Table

The following table describes the available actions and their behaviors:

| Action | Description | Result Behavior |
|--------|-------------|-----------------|
| Insert Template | Insert content from the specified LLM_TEMPLATE page | Direct insertion at cursor position |
| Complete | Automatically complete partial text based on context | Replace selected text or insert at cursor |
| Rewrite | Improve clarity and flow of existing text | Replace selected text |
| Grammar | Correct grammar and spelling errors | Replace selected text |
| Summarize | Create concise summaries of longer texts | Replace selected text or show in modal |
| Conclusion | Generate well-structured conclusions based on content | Append at end of document |
| Analyze | Perform detailed analysis of text content | Show in modal dialog |
| Continue | Continue writing from the current text | Insert after selected text |
| Custom Prompt | Process text with your own custom instructions | Based on custom prompt design |

Each action uses specific prompt templates that can be customized by editing pages in the `dokullm:prompts` namespace.

## Security Considerations

- Keep your API keys secure
- Be cautious about sending sensitive content to external LLM services
- The plugin only communicates with the configured API endpoint

## License

This plugin is licensed under the GNU General Public License v2.0.

## Author

Costin Stroie <costinstroie@eridu.eu.org>

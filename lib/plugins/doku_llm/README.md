# DokuLLM Plugin

A DokuWiki plugin that integrates Large Language Models (LLM) to enhance content creation and editing capabilities.

## Features

This plugin adds a toolbar to DokuWiki's edit interface with the following LLM-powered functions:

- **Complete**: Automatically complete partial text
- **Rewrite**: Improve clarity and flow of existing text
- **Grammar**: Correct grammar and spelling errors
- **Summarize**: Create concise summaries of longer texts
- **Conclusion**: Generate well-structured conclusions based on content
- **Translate**: Translate text to different languages

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

## Usage

1. Navigate to any page in edit mode
2. You'll see a toolbar with LLM action buttons above the text editor
3. Either:
   - Select specific text and click an action button to process only that text
   - Click an action button without selecting text to process the entire content
4. For translation, you'll be prompted to enter the target language

## How It Works

The plugin works by sending selected text to your configured LLM API with specific prompts for each function. The processed result is then inserted back into the editor.

## Security Considerations

- Keep your API keys secure
- Be cautious about sending sensitive content to external LLM services
- The plugin only communicates with the configured API endpoint

## License

This plugin is licensed under the GNU General Public License v2.0.

## Author

Costin Stroie <costinstroie@eridu.eu.org>

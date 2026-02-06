# Postal Warmup Pro

**Professional Postal Server Warmup Automation for WordPress.**

Postal Warmup Pro helps you automate the IP warmup process for your Postal mail servers. Manage multiple servers, create complex JSON templates, and track your delivery success in real-time.

## Features

*   **Multi-Server Management**: Add, edit, and monitor unlimited Postal servers.
*   **Secure**: API Keys are encrypted (AES-256). Webhooks are verified.
*   **Advanced Templates**: JSON-based templates with subject/body variants and variable support.
*   **Async Sending**: Uses Action Scheduler for reliable background processing.
*   **Real-time Analytics**: Dashboard with success rates, volumes, and error tracking.
*   **Logging**: Detailed logs with rotation and "File Only" mode for performance.

## Installation

1.  Upload the `postal-warmup` folder to `/wp-content/plugins/`.
2.  Run `composer install` in the plugin directory to install dependencies.
3.  Activate the plugin in WordPress.
4.  Go to **Postal Warmup > Servers** to add your first server.
5.  Configure the Webhook in Postal (see Settings page for URL).

## Requirements

*   PHP 8.1+
*   WordPress 5.8+
*   Postal API Access

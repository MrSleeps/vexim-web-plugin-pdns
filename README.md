
![VExim Web UI Logo](https://raw.githubusercontent.com/MrSleeps/VExim-Web-UI/refs/heads/main/public/images/logo.svg)

# A VExim Web UI plugin that allows VExim Web UI to interact with a PowerDNS API

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mrsleeps/vexim-web-plugin-pdns.svg?style=flat-square)](https://packagist.org/packages/mrsleeps/vexim-web-plugin-pdns)

Adding this plugin to your VExim Web UI will allow you to automatically publish DNS records to your PowerDNS server. This is currently used for DKIM records.

## Installation

You can install the package via composer:

```bash
composer require mrsleeps/vexim-web-plugin-pdns
```

This will also install the vexim-web-plugin-dns-core package which allows plugins to interact with the VExim Web UI.

You will need to run the migrations (adds a couple of tables) with:

```bash
php artisan migrate
```

[More information about this plugin can be found in the Wiki.](https://github.com/MrSleeps/VExim-Web-UI/wiki)

[Please add any issues to the main VExim Web UI issues page on GitHub.](https://github.com/MrSleeps/VExim-Web-UI/issues)

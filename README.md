
![VExim Web UI Logo](https://raw.githubusercontent.com/MrSleeps/VExim-Web-UI/refs/heads/main/public/images/logo.svg)
# A VExim Web UI plugin that allows VExim Web UI to interact with a PowerDNS API

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mrsleeps/vexim-pdns.svg?style=flat-square)](https://packagist.org/packages/mrsleeps/vexim-pdns)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mrsleeps/vexim-pdns/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/mrsleeps/vexim-pdns/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/mrsleeps/vexim-pdns/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/mrsleeps/vexim-pdns/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/mrsleeps/vexim-pdns.svg?style=flat-square)](https://packagist.org/packages/mrsleeps/vexim-pdns)



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

Next you will need to add the following to your *app/Providers/Filament/VeximPanelProvider.php* file under the $plugins variable:

```
            \VEximUI\DnsCore\DnsCorePlugin::make(),
            \VEximUI\VEximPdns\VEximPdnsPlugin::make()


```

Once you have entered those. you will have a menu option available to System Admins called "DNS Management", here you can add your PowerDNS API information.

You will need to link your domains (you can pick and choose what domains will automatically update) by going to the Domains page and editing your chosen domain.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Mr Sleeps](https://github.com/MrSleeps)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

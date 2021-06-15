# Elgentos' EU Tax Rates Importer

This Magento 2 module makes it possible to import a CSV file containing all the tax rates for all countries in the EU. 
It will also create the related tax rules used in Magento with some basic configuration. 

## Installation

This package can be installed using [Composer](https://getcomposer.com):

```bash
composer require elgentos/module-eu-tax-rates
bin/magento module:enable Elgentos_EuTaxRates
bin/magento setup:upgrade
```

## Usage

To use this script, you can run the following command on your command line: 

```bash
bin/magento elgentos:eutaxes:create [--reset=1]
```

When setting the `reset` option, the existing tax rates and tax rules will be removed. If not set, only non-existing
tax rates will be imported and the tax rule will be updated as well.

## Contributing
Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

Please make sure to update tests as appropriate.

## License
[OSL-3.0](https://opensource.org/licenses/OSL-3.0)

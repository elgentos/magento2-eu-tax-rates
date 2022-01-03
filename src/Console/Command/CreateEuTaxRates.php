<?php

/**
 * Copyright Elgentos. All rights reserved.
 * https://www.elgentos.nl
 */

declare(strict_types=1);

namespace Elgentos\EuTaxRates\Console\Command;

use Exception;
use Magento\Directory\Model\Region;
use Magento\Directory\Model\ResourceModel\Region\Collection as RegionCollection;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\InputException;
use Magento\Framework\File\Csv as CsvFileReader;
use Magento\Tax\Model\Calculation\Rate;
use Magento\Tax\Model\Calculation\RateFactory;
use Magento\Tax\Model\Calculation\RateRepository;
use Magento\Tax\Model\Calculation\RuleFactory;
use Magento\Tax\Model\ClassModel;
use Magento\Tax\Model\ResourceModel\Calculation\Rate\Collection as RateCollection;
use Magento\Tax\Model\ResourceModel\Calculation\Rate\CollectionFactory as RateCollectionFactory;
use Magento\Tax\Model\ResourceModel\Calculation\Rule\Collection as RuleCollection;
use Magento\Tax\Model\ResourceModel\Calculation\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\Tax\Model\ResourceModel\TaxClass\CollectionFactory as TaxClassCollectionFactory;
use Magento\Tax\Model\TaxRuleRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateEuTaxRates extends Command
{
    private const TAX_RATES_CSV_FILE = 'data/rates.csv',
        COMMAND_NAME                 = 'elgentos:eutaxes:create',
        COMMAND_DESCRIPTION          = 'Generate and install the tax rates for the whole EU.';

    private CsvFileReader $csvFileReader;

    private ComponentRegistrarInterface $componentRegistrar;

    private RateFactory $taxRateFactory;

    private RateCollectionFactory $rateCollection;

    private RateRepository $rateRepository;

    private RuleFactory $ruleFactory;

    private RuleCollectionFactory $ruleCollection;

    private TaxRuleRepository $ruleRepository;

    private TaxClassCollectionFactory $taxClassCollection;

    private RegionCollectionFactory $regionCollection;

    private array $taxRules = [];

    public function __construct(
        CsvFileReader $csvFileReader,
        ComponentRegistrarInterface $componentRegistrar,
        RateFactory $taxRateFactory,
        RateCollectionFactory $rateCollection,
        RateRepository $rateRepository,
        RuleCollectionFactory $ruleCollection,
        RuleFactory $ruleFactory,
        TaxRuleRepository $ruleRepository,
        TaxClassCollectionFactory $taxClassCollection,
        RegionCollectionFactory $regionCollectionFactory,
        string $name = null
    ) {
        $this->csvFileReader      = $csvFileReader;
        $this->componentRegistrar = $componentRegistrar;
        $this->taxRateFactory     = $taxRateFactory;
        $this->rateCollection     = $rateCollection;
        $this->rateRepository     = $rateRepository;
        $this->ruleCollection     = $ruleCollection;
        $this->ruleFactory        = $ruleFactory;
        $this->ruleRepository     = $ruleRepository;
        $this->taxClassCollection = $taxClassCollection;
        $this->regionCollection   = $regionCollectionFactory;

        parent::__construct($name ?? self::COMMAND_NAME);
    }

    protected function configure(): void
    {
        $options = [
            new InputOption(
                'reset',
                'r',
                InputOption::VALUE_OPTIONAL,
                'Reset (remove) the existing rates?'
            ),
            new InputOption(
                'file',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Fetch the rates from a custom file'
            )
        ];

        $this->setDescription(self::COMMAND_DESCRIPTION)
            ->setDefinition($options);
    }

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('reset')) {
            $output->writeln('Reset and remove all previously installed tax rates and rules');
            $this->resetAllTaxRates();
            $this->resetAllTaxRules();
        }

        $rates = $this->getTaxRates($input->getOption('file'));

        $output->writeln(
            sprintf('Found %d taxrates to import', count($rates))
        );

        foreach ($rates as $rate) {
            $type = $rate['type'];

            if (!isset($this->taxRules[$type])) {
                $this->taxRules[$type] = [];
            }

            $id = $this->rateAlreadyExists($rate['code']) ?: $this->storeTaxRate($rate);

            if ($id) {
                $this->taxRules[$type][] = $id;
            }
        }

        $output->writeln(
            sprintf('Create %d new tax rules based on the imported taxrates.', count($this->taxRules))
        );
        $this->createTaxRules();

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @throws Exception
     */
    private function getTaxRates(?string $file): array
    {
        $data = [];

        if (!$file) {
            $moduleDir = $this->componentRegistrar->getPath(
                ComponentRegistrar::MODULE,
                'Elgentos_EuTaxRates'
            );
            $file      = $moduleDir . '/' . self::TAX_RATES_CSV_FILE;
        }

        $content     = $this->csvFileReader->getData($file);
        $keys        = [];
        $isFirstLine = true;

        foreach ($content as $row) {
            if ($isFirstLine) {
                $keys        = $row;
                $isFirstLine = false;

                continue;
            }

            $data[] = array_combine($keys, $row);
        }

        return $data;
    }

    private function rateAlreadyExists(string $code): int
    {
        /** @var RateCollection $collection */
        $collection = $this->rateCollection->create();

        /** @var Rate $rate */
        $rate = $collection->addFieldToFilter('code', $code)
            ->getFirstItem();

        return (int) $rate->getId();
    }

    private function resetAllTaxRates(): void
    {
        /** @var RateCollection $collection */
        $collection = $this->rateCollection->create();

        /** @var int $id */
        foreach ($collection->getAllIds() as $id) {
            try {
                $this->rateRepository->deleteById($id);
            } catch (Exception $e) {
                continue;
            }
        }
    }

    private function resetAllTaxRules(): void
    {
        /** @var RuleCollection $collection */
        $collection = $this->ruleCollection->create();

        foreach ($collection->getAllIds() as $id) {
            try {
                $this->ruleRepository->deleteById($id);
            } catch (Exception $e) {
                continue;
            }
        }
    }

    /**
     * @throws InputException
     */
    protected function storeTaxRate(array $rate): ?int
    {
        $taxRate              = $this->taxRateFactory->create();
        $rate['tax_postcode'] = empty($rate['tax_postcode'])
            ? '*'
            : $rate['tax_postcode'];

        if (!empty($rate['tax_region_id'])) {
            $rate['tax_region_id'] = $this->getRegionIdByName($rate);
        }

        $taxRate->setData($rate);
        $taxRateInstance = $this->rateRepository->save($taxRate);

        return (int) $taxRateInstance->getId();
    }

    /**
     * @throws InputException
     */
    private function createTaxRules(): void
    {
        foreach ($this->taxRules as $type => $rule) {
            $taxRule = $this->ruleFactory->create();
            $taxRule->setCode(sprintf('Tax Rule - %s', $type))
                ->setTaxRateIds($rule)
                ->setPriority(0)
                ->setCustomerTaxClassIds($this->getTaxClassIds(ClassModel::TAX_CLASS_TYPE_CUSTOMER))
                ->setProductTaxClassIds($this->getTaxClassIds(ClassModel::TAX_CLASS_TYPE_PRODUCT));

            $this->ruleRepository->save($taxRule);
        }
    }

    private function getTaxClassIds(string $type): array
    {
        $collection = $this->taxClassCollection->create();

        return $collection->setClassTypeFilter($type)
            ->getAllIds();
    }

    private function getRegionIdByName(array $rate): string
    {
        /** @var RegionCollection $collection */
        $collection = $this->regionCollection->create();
        $collection->addCountryFilter($rate['tax_country_id'])
            ->addRegionCodeFilter($rate['tax_region_id']);

        /** @var Region $region */
        $region = $collection->getFirstItem();

        return (string) $region->getId() ?: '';
    }
}

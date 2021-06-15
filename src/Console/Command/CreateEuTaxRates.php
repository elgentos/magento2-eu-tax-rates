<?php

/**
 * Copyright Elgentos. All rights reserved.
 * https://www.elgentos.nl
 */

declare(strict_types=1);

namespace Elgentos\EuTaxRates\Console\Command;

use Exception;
use Magento\Directory\Model\Region;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\File\Csv as CsvFileReader;
use Magento\Tax\Model\Calculation\Rate;
use Magento\Tax\Model\Calculation\RateFactory;
use Magento\Tax\Model\Calculation\RateRepository;
use Magento\Tax\Model\Calculation\RuleFactory;
use Magento\Tax\Model\ClassModel;
use Magento\Tax\Model\ResourceModel\Calculation\Rate\CollectionFactory as RateCollectionFactory;
use Magento\Tax\Model\ResourceModel\Calculation\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\Tax\Model\ResourceModel\TaxClass\CollectionFactory as TaxClassCollectionFactory;
use Magento\Tax\Model\TaxRuleRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateEuTaxRates extends Command
{
    private const TAX_RATES_CSV_FILE = 'data/rates.csv';

    /** @var CsvFileReader */
    private CsvFileReader $csvFileReader;

    /** @var ComponentRegistrarInterface */
    private ComponentRegistrarInterface $componentRegistrar;

    /** @var RateFactory */
    private RateFactory $taxRateFactory;

    /** @var RateCollectionFactory */
    private RateCollectionFactory $rateCollection;

    /** @var RateRepository */
    private RateRepository $rateRepository;

    /** @var RuleFactory */
    private RuleFactory $ruleFactory;

    /** @var RuleCollectionFactory */
    private RuleCollectionFactory $ruleCollection;

    /** @var TaxRuleRepository */
    private TaxRuleRepository $ruleRepository;

    /** @var TaxClassCollectionFactory */
    private TaxClassCollectionFactory $taxClassCollection;

    /** @var RegionCollectionFactory */
    private RegionCollectionFactory $regionCollection;

    /** @var array */
    private array $taxRules = [];

    /**
     * Constructor.
     *
     * @param CsvFileReader               $csvFileReader
     * @param ComponentRegistrarInterface $componentRegistrar
     * @param RateFactory                 $taxRateFactory
     * @param RateCollectionFactory       $rateCollection
     * @param RateRepository              $rateRepository
     * @param RuleCollectionFactory       $ruleCollection
     * @param RuleFactory                 $ruleFactory
     * @param TaxRuleRepository           $ruleRepository
     * @param TaxClassCollectionFactory   $taxClassCollection
     * @param RegionCollectionFactory     $regionCollectionFactory
     * @param string|null                 $name
     */
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

        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $options = [
            new InputOption(
                'reset',
                null,
                InputOption::VALUE_OPTIONAL,
                'Reset (remove) the existing rates?'
            )
        ];

        $this->setName('elgentos:eutaxes:create')
            ->setDescription('Generate and install the tax rates for the whole EU.')
            ->setDefinition($options);
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return int
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('reset')) {
            $output->writeln('Reset and remove all previously installed tax rates and rules');
            $this->resetAllTaxRates();
            $this->resetAllTaxRules();
        }

        $rates = $this->getTaxRates();

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

        return 0;
    }

    /**
     * @return array
     * @throws Exception
     */
    private function getTaxRates(): array
    {
        $data = [];

        try {
            $moduleDir = $this->componentRegistrar->getPath(
                ComponentRegistrar::MODULE,
                'Elgentos_EuTaxRates'
            );

            $content     = $this->csvFileReader->getData($moduleDir . '/' . self::TAX_RATES_CSV_FILE);
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
        } catch (Exception $e) {
            echo $e->getMessage();
        }

        return $data;
    }

    /**
     * @param string $code
     *
     * @return int
     */
    private function rateAlreadyExists(string $code): int
    {
        $collection = $this->rateCollection->create();

        /** @var Rate $rate */
        $rate = $collection->addFieldToFilter('code', $code)
            ->getFirstItem();

        return (int) $rate->getId();
    }

    /**
     * @return void
     */
    private function resetAllTaxRates(): void
    {
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

    /**
     * @return void
     */
    private function resetAllTaxRules(): void
    {
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
     * @param array $rate
     *
     * @return int|null
     * @throws InputException
     */
    protected function storeTaxRate(array $rate): ?int
    {
        $taxRate              = $this->taxRateFactory->create();
        $rate['tax_postcode'] = empty($rate['tax_postcode']) && empty($rate['zip_is_range'])
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
     * @return void
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

    /**
     * @param string $type
     *
     * @return array
     */
    private function getTaxClassIds(string $type): array
    {
        $collection = $this->taxClassCollection->create();

        return $collection->setClassTypeFilter($type)
            ->getAllIds();
    }

    /**
     * @param array $rate
     *
     * @return string|int
     */
    private function getRegionIdByName(array $rate)
    {
        $collection = $this->regionCollection->create();
        $collection->addCountryFilter($rate['tax_country_id'])
            ->addRegionCodeFilter($rate['tax_region_id']);

        /** @var Region $region */
        $region = $collection->getFirstItem();

        return $region->getId() ?: '';
    }
}

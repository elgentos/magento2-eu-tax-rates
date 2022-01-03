<?php

declare(strict_types=1);

namespace Elgentos\EuTaxRates\Test\Console\Command;

use Elgentos\EuTaxRates\Console\Command\CreateEuTaxRates;
use Magento\Directory\Model\Region;
use Magento\Directory\Model\ResourceModel\Region\Collection as RegionCollection;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\File\Csv as CsvFileReader;
use Magento\Tax\Model\Calculation\Rate;
use Magento\Tax\Model\Calculation\RateFactory;
use Magento\Tax\Model\Calculation\RateRepository;
use Magento\Tax\Model\Calculation\Rule;
use Magento\Tax\Model\Calculation\RuleFactory;
use Magento\Tax\Model\ResourceModel\Calculation\Rate\Collection as RateCollection;
use Magento\Tax\Model\ResourceModel\Calculation\Rate\CollectionFactory as RateCollectionFactory;
use Magento\Tax\Model\ResourceModel\Calculation\Rule\Collection as RuleCollection;
use Magento\Tax\Model\ResourceModel\Calculation\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\Tax\Model\ResourceModel\TaxClass\Collection as TaxClassCollection;
use Magento\Tax\Model\ResourceModel\TaxClass\CollectionFactory as TaxClassCollectionFactory;
use Magento\Tax\Model\TaxRuleRepository;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @coversDefaultClass \Elgentos\EuTaxRates\Console\Command\CreateEuTaxRates
 */
class CreateEuTaxRatesTest extends TestCase
{
    public function setDataProvider(): array
    {
        return [
            'hasCustomFile' => [true],
            'throwsRuleException' => [false, true],
            'throwsRateException' => [false, false, true],
            'storeTaxRate' => [false, false, false, true]
        ];
    }

    /**
     * @throws ReflectionException
     *
     * @dataProvider setDataProvider
     */
    public function testExecute(
        bool $hasCustomFile = false,
        bool $throwsRuleException = false,
        bool $throwsRateException = false,
        bool $storeTaxRate = false
    ): void {
        $csvReader = $this->createMock(CsvFileReader::class);
        $csvReader->expects(self::once())
            ->method('getData')
            ->willReturn($this->getCsvDummyResult());

        $subject = new CreateEuTaxRates(
            $csvReader,
            $this->createMock(ComponentRegistrarInterface::class),
            $this->createTaxRateFactoryMock(),
            $this->createRateCollectionFactoryMock($storeTaxRate),
            $this->createRateRepositoryMock($throwsRateException),
            $this->createRuleCollectionFactoryMock(),
            $this->createRuleFactory(),
            $this->createTaxRuleReposityMock($throwsRuleException),
            $this->createTaxClassCollectionFactoryMock(),
            $this->createRegionCollectionFactoryMock()
        );

        $reflectionMethod = new ReflectionMethod($subject, 'execute');
        $reflectionMethod->setAccessible(true);

        $result = $reflectionMethod->invoke(
            $subject,
            $this->createInputInterfaceMock($hasCustomFile),
            $this->createMock(OutputInterface::class)
        );
    }

    private function createRateCollectionFactoryMock(
        bool $storeTaxRate
    ): RateCollectionFactory {
        $factory = $this->getMockBuilder(RateCollectionFactory::class)
            ->allowMockingUnknownTypes()
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $collection = $this->createMock(RateCollection::class);
        $collection->expects(self::any())
            ->method('getAllIds')
            ->willReturn([1, 2, 3]);

        $collection->expects(self::any())
            ->method('addFieldToFilter')
            ->willReturn($collection);

        $rate = $this->createMock(Rate::class);
        $rate->expects(self::any())
            ->method('getId')
            ->willReturn($storeTaxRate ? false : 1);

        $collection->expects(self::any())
            ->method('getFirstItem')
            ->willReturn($rate);

        $factory->expects(self::any())
            ->method('create')
            ->willReturn($collection);

        return $factory;
    }

    private function createRuleCollectionFactoryMock(): RuleCollectionFactory
    {
        $factory = $this->getMockBuilder(RuleCollectionFactory::class)
            ->allowMockingUnknownTypes()
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $collection = $this->createMock(RuleCollection::class);
        $collection->expects(self::any())
            ->method('getAllIds')
            ->willReturn([1, 2, 3]);

        $factory->expects(self::any())
            ->method('create')
            ->willReturn($collection);

        return $factory;
    }

    private function createRuleFactory(): RuleFactory
    {
        $factory = $this->getMockBuilder(RuleFactory::class)
            ->allowMockingUnknownTypes()
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $rule = $this->createMock(Rule::class);
        $rule->expects(self::any())
            ->method('setCode')
            ->willReturn($rule);

        $rule->expects(self::any())
            ->method('setTaxRateIds')
            ->willReturn($rule);

        $rule->expects(self::any())
            ->method('setPriority')
            ->willReturn($rule);

        $rule->expects(self::any())
            ->method('setCustomerTaxClassIds')
            ->willReturn($rule);

        $rule->expects(self::any())
            ->method('setProductTaxClassIds')
            ->willReturn($rule);

        $factory->expects(self::once())
            ->method('create')
            ->willReturn($rule);

        return $factory;
    }

    private function createTaxClassCollectionFactoryMock(): TaxClassCollectionFactory
    {
        $factory = $this->getMockBuilder(TaxClassCollectionFactory::class)
            ->allowMockingUnknownTypes()
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $collection = $this->createMock(TaxClassCollection::class);
        $collection->expects(self::any())
            ->method('setClassTypeFilter')
            ->willReturn($collection);

        $collection->expects(self::any())
            ->method('getAllIds')
            ->willReturn([1, 2, 3, 4]);

        $factory->expects(self::any())
            ->method('create')
            ->willReturn($collection);

        return $factory;
    }

    private function createRegionCollectionFactoryMock(): RegionCollectionFactory
    {
        $factory = $this->getMockBuilder(RegionCollectionFactory::class)
            ->allowMockingUnknownTypes()
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();

        $collection = $this->createMock(RegionCollection::class);
        $collection->expects(self::any())
            ->method('addCountryFilter')
            ->willReturn($collection);

        $collection->expects(self::any())
            ->method('addRegionCodeFilter')
            ->willReturn($collection);

        $collection->expects(self::any())
            ->method('getFirstItem')
            ->willReturn($this->createMock(Region::class));

        $factory->expects(self::any())
            ->method('create')
            ->willReturn($collection);

        return $factory;
    }

    private function createInputInterfaceMock(
        bool $hasCustomFile
    ): InputInterface {
        $inputInterface = $this->createMock(InputInterface::class);
        $inputInterface->expects(self::any())
            ->method('getOption')
            ->withConsecutive(['reset'], ['file'])
            ->willReturn(1, $hasCustomFile ? 'foobar.csv' : null);

        return $inputInterface;
    }

    private function createTaxRuleReposityMock(
        bool $throwsRuleException
    ) {
        $repository = $this->createMock(TaxRuleRepository::class);

        if (!$throwsRuleException) {
            $repository->expects(self::any())
                ->method('deleteById')
                ->willReturn(true);
        } else {
            $repository->expects(self::any())
                ->method('deleteById')
                ->willThrowException(new NoSuchEntityException(__('Something went wrong')));
        }

        return $repository;
    }

    private function createRateRepositoryMock(
        bool $throwsRateException
    ) {
        $rateRepository = $this->createMock(RateRepository::class);

        if (!$throwsRateException) {
            $rateRepository->expects(self::any())
                ->method('deleteById')
                ->willReturn(true);
        } else {
            $rateRepository->expects(self::any())
                ->method('deleteById')
                ->willThrowException(new NoSuchEntityException(__('Something went wrong')));
        }

        $rateRepository->expects(self::any())
            ->method('save')
            ->willReturn($this->createMock(Rate::class));

        return $rateRepository;
    }

    private function createTaxRateFactoryMock()
    {
        $factory = $this->createMock(RateFactory::class);

        $rate = $this->createMock(Rate::class);
        $rate->expects(self::any())
            ->method('setData')
            ->willReturn($rate);

        $factory->expects(self::any())
            ->method('create')
            ->willReturn($rate);

        return $factory;
    }

    private function getCsvDummyResult(): array
    {
        return [
            [
                'code',
                'tax_country_id',
                'tax_region_id',
                'tax_postcode',
                'rate',
                'zip_in_range',
                'zip_from',
                'zip_to',
                'type'
            ],
            [
                'Netherlands (standard)',
                'NL',
                '',
                '',
                21,
                '',
                '',
                '',
                'STANDARD'
            ],
            [
                'Spain (Tenerife) (standard)',
                'ES',
                'Santa Cruz de Tenerife',
                '',
                0,
                '',
                '',
                '',
                'STANDARD'
            ],
            [
                'Netherlands (Vlieland) (standard)',
                'NL',
                '',
                '8899AA',
                20,
                '',
                '',
                '',
                'STANDARD'
            ]
        ];
    }
}

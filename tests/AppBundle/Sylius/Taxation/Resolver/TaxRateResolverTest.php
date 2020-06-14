<?php

namespace Tests\AppBundle\Sylius\Taxation\Resolver;

use AppBundle\Entity\Sylius\TaxCategory;
use AppBundle\Entity\Sylius\TaxRate;
use AppBundle\Sylius\Taxation\Resolver\TaxRateResolver;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Sylius\Component\Taxation\Model\TaxableInterface;
use Sylius\Component\Taxation\Model\TaxCategoryInterface;

class TaxRateResolverTest extends TestCase implements TaxableInterface
{
    use ProphecyTrait;

    public function setUp(): void
    {
        $this->taxRateRepository = $this->prophesize(RepositoryInterface::class);
    }

    public function getTaxCategory(): ?TaxCategoryInterface
    {
        return $this->taxCategory;
    }

    public function testLegacyTaxCategory()
    {
        $rate = new TaxRate();
        $rate->setAmount(0.1);

        $category = new TaxCategory();
        $category->addRate($rate);

        $resolver = new TaxRateResolver(
            $this->taxRateRepository->reveal(),
            'fr'
        );

        $this->taxCategory = $category;

        $rates = $resolver->resolveMulti($this);

        $this->assertCount(1, $rates);

        $this->taxRateRepository->findBy(Argument::type('array'))->shouldNotHaveBeenCalled();
    }

    public function testTaxCategoryWithMultipleRates()
    {
        $gst = new TaxRate();
        $gst->setAmount(0.05);

        $pst = new TaxRate();
        $pst->setAmount(0.07);

        $category = new TaxCategory();
        $category->addRate($gst);
        $category->addRate($pst);

        $this->taxRateRepository
            ->findBy([
                'category' => $category,
                'country'  => 'ca-bc',
            ])
            ->willReturn([
                $gst,
                $pst,
            ]);

        $resolver = new TaxRateResolver(
            $this->taxRateRepository->reveal(),
            'ca-bc'
        );

        $this->taxCategory = $category;

        $rates = $resolver->resolveMulti($this);

        $this->assertCount(2, $rates);
    }
}

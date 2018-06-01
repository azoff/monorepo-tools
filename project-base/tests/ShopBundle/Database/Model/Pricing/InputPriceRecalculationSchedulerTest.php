<?php

namespace Tests\ShopBundle\Database\Model\Pricing;

use Shopsys\FrameworkBundle\Component\Setting\Setting;
use Shopsys\FrameworkBundle\DataFixtures\Base\CurrencyDataFixture;
use Shopsys\FrameworkBundle\DataFixtures\Demo\ProductDataFixture;
use Shopsys\FrameworkBundle\Model\Payment\PaymentData;
use Shopsys\FrameworkBundle\Model\Payment\PaymentDataFactory;
use Shopsys\FrameworkBundle\Model\Payment\PaymentFacade;
use Shopsys\FrameworkBundle\Model\Pricing\InputPriceRecalculationScheduler;
use Shopsys\FrameworkBundle\Model\Pricing\InputPriceRecalculator;
use Shopsys\FrameworkBundle\Model\Pricing\PricingSetting;
use Shopsys\FrameworkBundle\Model\Pricing\Vat\Vat;
use Shopsys\FrameworkBundle\Model\Pricing\Vat\VatData;
use Shopsys\FrameworkBundle\Model\Product\Availability\Availability;
use Shopsys\FrameworkBundle\Model\Product\Availability\AvailabilityData;
use Shopsys\FrameworkBundle\Model\Product\Product;
use Shopsys\FrameworkBundle\Model\Product\ProductDataFactory;
use Shopsys\FrameworkBundle\Model\Product\ProductFacade;
use Shopsys\FrameworkBundle\Model\Transport\TransportData;
use Shopsys\FrameworkBundle\Model\Transport\TransportFacade;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Tests\ShopBundle\Test\DatabaseTestCase;

class InputPriceRecalculationSchedulerTest extends DatabaseTestCase
{
    public function testOnKernelResponseNoAction()
    {
        $setting = $this->getContainer()->get(Setting::class);
        /* @var $setting \Shopsys\FrameworkBundle\Component\Setting\Setting */

        $inputPriceRecalculatorMock = $this->getMockBuilder(InputPriceRecalculator::class)
            ->setMethods(['__construct', 'recalculateToInputPricesWithoutVat', 'recalculateToInputPricesWithVat'])
            ->disableOriginalConstructor()
            ->getMock();
        $inputPriceRecalculatorMock->expects($this->never())->method('recalculateToInputPricesWithoutVat');
        $inputPriceRecalculatorMock->expects($this->never())->method('recalculateToInputPricesWithVat');

        $filterResponseEventMock = $this->getMockBuilder(FilterResponseEvent::class)
            ->disableOriginalConstructor()
            ->setMethods(['isMasterRequest'])
            ->getMock();
        $filterResponseEventMock->expects($this->any())->method('isMasterRequest')
            ->willReturn(true);

        $inputPriceRecalculationScheduler = new InputPriceRecalculationScheduler($inputPriceRecalculatorMock, $setting);

        $inputPriceRecalculationScheduler->onKernelResponse($filterResponseEventMock);
    }

    public function inputPricesTestDataProvider()
    {
        return [
            ['inputPriceWithoutVat' => '100', 'inputPriceWithVat' => '121', 'vatPercent' => '21'],
            ['inputPriceWithoutVat' => '17261.983471', 'inputPriceWithVat' => '20887', 'vatPercent' => '21'],
        ];
    }

    /**
     * @param string $inputPrice
     * @param string $vatPercent
     * @return \Shopsys\FrameworkBundle\Model\Product\Product
     */
    private function createProductWithInputPriceAndVatPercentAndAutoCalculationPriceType(
        $inputPrice,
        $vatPercent
    ) {
        $em = $this->getEntityManager();
        $productDataFactory = $this->getContainer()->get(ProductDataFactory::class);
        /* @var $productDataFactory \Shopsys\FrameworkBundle\Model\Product\ProductDataFactory */
        $productFacade = $this->getContainer()->get(ProductFacade::class);
        /* @var $productFacade \Shopsys\FrameworkBundle\Model\Product\ProductFacade */

        $vat = new Vat(new VatData('vat', $vatPercent));
        $em->persist($vat);

        $templateProduct = $this->getReference(ProductDataFixture::PRODUCT_PREFIX . '1');
        $productData = $productDataFactory->createFromProduct($templateProduct);
        $productData->priceCalculationType = Product::PRICE_CALCULATION_TYPE_AUTO;
        $productData->price = $inputPrice;
        $productData->vat = $vat;

        return $productFacade->create($productData);
    }

    /**
     * @dataProvider inputPricesTestDataProvider
     */
    public function testOnKernelResponseRecalculateInputPricesWithoutVat(
        $inputPriceWithoutVat,
        $inputPriceWithVat,
        $vatPercent
    ) {
        $em = $this->getEntityManager();

        $setting = $this->getContainer()->get(Setting::class);
        /* @var $setting \Shopsys\FrameworkBundle\Component\Setting\Setting */
        $inputPriceRecalculationScheduler = $this->getContainer()->get(InputPriceRecalculationScheduler::class);
        /* @var $inputPriceRecalculationScheduler \Shopsys\FrameworkBundle\Model\Pricing\InputPriceRecalculationScheduler */
        $paymentFacade = $this->getContainer()->get(PaymentFacade::class);
        /* @var $paymentFacade \Shopsys\FrameworkBundle\Model\Payment\PaymentFacade */
        $transportFacade = $this->getContainer()->get(TransportFacade::class);
        /* @var $transportFacade \Shopsys\FrameworkBundle\Model\Transport\TransportFacade */
        $paymentDataFactory = $this->getContainer()->get(PaymentDataFactory::class);
        /* @var $paymentDataFactory \Shopsys\FrameworkBundle\Model\Payment\PaymentDataFactory */

        $setting->set(PricingSetting::INPUT_PRICE_TYPE, PricingSetting::INPUT_PRICE_TYPE_WITH_VAT);

        $vat = new Vat(new VatData('vat', $vatPercent));
        $availability = new Availability(new AvailabilityData([], 0));
        $em->persist($vat);
        $em->persist($availability);

        $currency1 = $this->getReference(CurrencyDataFixture::CURRENCY_CZK);
        $currency2 = $this->getReference(CurrencyDataFixture::CURRENCY_EUR);

        $product = $this->createProductWithInputPriceAndVatPercentAndAutoCalculationPriceType(
            $inputPriceWithVat,
            $vatPercent
        );

        $paymentData = $paymentDataFactory->createDefault();
        $paymentData->name = ['cs' => 'name'];
        $paymentData->pricesByCurrencyId = [$currency1->getId() => $inputPriceWithVat, $currency2->getId() => $inputPriceWithVat];
        $paymentData->vat = $vat;
        $payment = $paymentFacade->create($paymentData);
        /* @var $payment \Shopsys\FrameworkBundle\Model\Payment\Payment */

        $transportData = new \Shopsys\FrameworkBundle\Model\Transport\TransportData();
        $transportData->name = ['cs' => 'name'];
        $transportData->description = ['cs' => 'desc'];
        $transportData->pricesByCurrencyId = [$currency1->getId() => $inputPriceWithVat, $currency2->getId() => $inputPriceWithVat];
        $transportData->vat = $vat;
        $transport = $transportFacade->create($transportData);
        /* @var $transport \Shopsys\FrameworkBundle\Model\Transport\Transport */
        $em->flush();

        $filterResponseEventMock = $this->getMockBuilder(FilterResponseEvent::class)
            ->disableOriginalConstructor()
            ->setMethods(['isMasterRequest'])
            ->getMock();
        $filterResponseEventMock->expects($this->any())->method('isMasterRequest')
            ->willReturn(true);

        $inputPriceRecalculationScheduler->scheduleSetInputPricesWithoutVat();
        $inputPriceRecalculationScheduler->onKernelResponse($filterResponseEventMock);

        $em->refresh($product);
        $em->refresh($payment);
        $em->refresh($transport);

        $this->assertSame(round($inputPriceWithoutVat, 6), round($product->getPrice(), 6));
        $this->assertSame(round($inputPriceWithoutVat, 6), round($payment->getPrice($currency1)->getPrice(), 6));
        $this->assertSame(round($inputPriceWithoutVat, 6), round($transport->getPrice($currency1)->getPrice(), 6));
    }

    /**
     * @dataProvider inputPricesTestDataProvider
     */
    public function testOnKernelResponseRecalculateInputPricesWithVat(
        $inputPriceWithoutVat,
        $inputPriceWithVat,
        $vatPercent
    ) {
        $em = $this->getEntityManager();

        $setting = $this->getContainer()->get(Setting::class);
        /* @var $setting \Shopsys\FrameworkBundle\Component\Setting\Setting */
        $inputPriceRecalculationScheduler = $this->getContainer()->get(InputPriceRecalculationScheduler::class);
        /* @var $inputPriceRecalculationScheduler \Shopsys\FrameworkBundle\Model\Pricing\InputPriceRecalculationScheduler */
        $paymentFacade = $this->getContainer()->get(PaymentFacade::class);
        /* @var $paymentFacade \Shopsys\FrameworkBundle\Model\Payment\PaymentFacade */
        $transportFacade = $this->getContainer()->get(TransportFacade::class);
        /* @var $transportFacade \Shopsys\FrameworkBundle\Model\Transport\TransportFacade */
        $paymentDataFactory = $this->getContainer()->get(PaymentDataFactory::class);
        /* @var $paymentDataFactory \Shopsys\FrameworkBundle\Model\Payment\PaymentDataFactory */

        $setting->set(PricingSetting::INPUT_PRICE_TYPE, PricingSetting::INPUT_PRICE_TYPE_WITHOUT_VAT);

        $currency1 = $this->getReference(CurrencyDataFixture::CURRENCY_CZK);
        $currency2 = $this->getReference(CurrencyDataFixture::CURRENCY_EUR);

        $vat = new Vat(new VatData('vat', $vatPercent));
        $availability = new Availability(new AvailabilityData([], 0));
        $em->persist($vat);
        $em->persist($availability);

        $product = $this->createProductWithInputPriceAndVatPercentAndAutoCalculationPriceType(
            $inputPriceWithoutVat,
            $vatPercent
        );

        $paymentData = $paymentDataFactory->createDefault();
        $paymentData->name = ['cs' => 'name'];
        $paymentData->pricesByCurrencyId = [$currency1->getId() => $inputPriceWithoutVat, $currency2->getId() => $inputPriceWithoutVat];
        $paymentData->vat = $vat;
        $payment = $paymentFacade->create($paymentData);
        /* @var $payment \Shopsys\FrameworkBundle\Model\Payment\Payment */

        $transportData = new TransportData();
        $transportData->name = ['cs' => 'name'];
        $transportData->pricesByCurrencyId = [$currency1->getId() => $inputPriceWithoutVat, $currency2->getId() => $inputPriceWithoutVat];
        $transportData->vat = $vat;
        $transport = $transportFacade->create($transportData);
        /* @var $transport \Shopsys\FrameworkBundle\Model\Transport\Transport */

        $em->flush();

        $filterResponseEventMock = $this->getMockBuilder(FilterResponseEvent::class)
            ->disableOriginalConstructor()
            ->setMethods(['isMasterRequest'])
            ->getMock();
        $filterResponseEventMock->expects($this->any())->method('isMasterRequest')
            ->willReturn(true);

        $inputPriceRecalculationScheduler->scheduleSetInputPricesWithVat();
        $inputPriceRecalculationScheduler->onKernelResponse($filterResponseEventMock);

        $em->refresh($product);
        $em->refresh($payment);
        $em->refresh($transport);

        $this->assertSame(round($inputPriceWithVat, 6), round($product->getPrice(), 6));
        $this->assertSame(round($inputPriceWithVat, 6), round($payment->getPrice($currency1)->getPrice(), 6));
        $this->assertSame(round($inputPriceWithVat, 6), round($transport->getPrice($currency1)->getPrice(), 6));
    }
}

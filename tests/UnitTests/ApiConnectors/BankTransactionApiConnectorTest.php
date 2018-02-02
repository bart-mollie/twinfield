<?php

namespace PhpTwinfield\UnitTests;

use Money\Currency;
use Money\Money;
use PhpTwinfield\ApiConnectors\BankTransactionApiConnector;
use PhpTwinfield\BankTransaction;
use PhpTwinfield\Enums\Destiny;
use PhpTwinfield\Enums\LineType;
use PhpTwinfield\Exception;
use PhpTwinfield\Office;
use PhpTwinfield\Response\Response;
use PhpTwinfield\Secure\Connection;
use PhpTwinfield\Services\ProcessXmlService;
use PhpTwinfield\Transactions\BankTransactionLine\Detail;
use PhpTwinfield\Transactions\BankTransactionLine\Total;
use PHPUnit\Framework\TestCase;

class BankTransactionApiConnectorTest extends TestCase
{
    /**
     * @var BankTransactionApiConnector
     */
    protected $apiConnector;

    /**
     * @var ProcessXmlService|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $processXmlService;

    /**
     * @var Office
     */
    protected $office;

    protected function setUp()
    {
        parent::setUp();

        $this->processXmlService = $this->getMockBuilder(ProcessXmlService::class)
            ->setMethods(["sendDocument"])
            ->disableOriginalConstructor()
            ->getMock();

        /** @var Connection|\PHPUnit_Framework_MockObject_MockObject $connection */
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->any())
            ->method("getAuthenticatedClient")
            ->willReturn($this->processXmlService);

        $this->apiConnector = new BankTransactionApiConnector($connection);
        $this->office = Office::fromCode("XXX101");
    }

    private function createBankTransaction(): BankTransaction
    {
        $banktransaction = new BankTransaction();
        $banktransaction->setDestiny(Destiny::TEMPORARY());
        $banktransaction->setOffice($this->office);

        return $banktransaction;
    }

    public function testSendAllReturnsMappedObjects()
    {
        $response = Response::fromString(file_get_contents(
            __DIR__."/resources/2-failed-and-1-successful-banktransactions.xml"
        ));

        $this->processXmlService->expects($this->once())
            ->method("sendDocument")
            ->willReturn($response);

        $responses = $this->apiConnector->sendAll([
            $this->createBankTransaction(),
            $this->createBankTransaction(),
            $this->createBankTransaction(),
        ]);

        $this->assertCount(3, $responses);

        [$response1, $response2, $response3] = $responses;

        try {
            $response1->unwrap();
        } catch (Exception $e) {
            $this->assertEquals("De boeking is niet in balans. Er ontbreekt 0.01 debet.//De boeking balanceert niet in de basisvaluta. Er ontbreekt 0.01 debet.//De boeking balanceert niet in de rapportagevaluta. Er ontbreekt 0.01 debet.", $e->getMessage());
        }

        try {
            $response2->unwrap();
        } catch (Exception $e) {
            $this->assertEquals("De boeking is niet in balans. Er ontbreekt 0.01 debet.//De boeking balanceert niet in de basisvaluta. Er ontbreekt 0.01 debet.//De boeking balanceert niet in de rapportagevaluta. Er ontbreekt 0.01 debet.", $e->getMessage());
        }

        /** @var BankTransaction $banktransaction3 */
        $banktransaction3 = $response3->unwrap();

        $this->assertEquals("BNK", $banktransaction3->getCode());
        $this->assertEquals("OFFICE001", $banktransaction3->getOffice()->getCode());
        $this->assertEquals("2017/08", $banktransaction3->getPeriod());
        $this->assertEquals(new Currency("EUR"), $banktransaction3->getCurrency());
        $this->assertEquals(Money::EUR(0), $banktransaction3->getStartvalue());
        $this->assertEquals(Money::EUR(0), $banktransaction3->getClosevalue());
        $this->assertEquals(0, $banktransaction3->getStatementnumber());
        $this->assertEquals("201700334", $banktransaction3->getNumber());

        $lines = $banktransaction3->getLines();
        $this->assertEquals(3, count($lines));

        /** @var Total $line */
        $line = $lines[0];
        $this->assertEquals("1", $line->getId());
        $this->assertEquals(LineType::TOTAL(), $line->getLineType());
        $this->assertEquals("1100", $line->getDim1());
        $this->assertEquals("debit", $line->getDebitCredit());
        $this->assertEquals(Money::EUR(0), $line->getValue());
        $this->assertEquals("2017.123456", $line->getInvoiceNumber());
        $this->assertEquals("2017.123456", $line->getDescription());
        $this->assertEquals("2017.123456", $line->getComment());

        /** @var Detail $line */
        $line = $lines[1];
        $this->assertEquals("2", $line->getId());
        $this->assertEquals(LineType::DETAIL(), $line->getLineType());
        $this->assertEquals("1800", $line->getDim1());
        $this->assertEquals("debit", $line->getDebitCredit());
        $this->assertEquals(Money::EUR(87), $line->getValue());
        $this->assertEquals("2017.123456", $line->getInvoiceNumber());
        $this->assertEquals("2017.123456", $line->getDescription());
        $this->assertEquals("2017.123456", $line->getComment());
    }
}
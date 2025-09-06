<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2025. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Helpers\SwissQr;

use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Scheduler;
use Sprain\SwissQrBill as QrBill;

/**
 * SwissQrPaymentScheduleGenerator.
 */
class SwissQrPaymentScheduleGenerator
{
    protected Company $company;

    protected $invoice;

    protected Client $client;

    protected $paymentSchedule;

    public function __construct($invoice, Company $company)
    {
        $this->company = $company;
        $this->invoice = $invoice;
        $this->client = $invoice->client;

        // Get payment schedule from scheduler
        $this->paymentSchedule = Scheduler::where('company_id', $this->company->id)
            ->where('template', 'payment_schedule')
            ->where('parameters->invoice_id', $this->invoice->hashed_id)
            ->first();
    }

    public function run()
    {
        if (!$this->paymentSchedule || !isset($this->paymentSchedule->parameters['schedule'])) {
            return '';
        }

        $scheduleArray = $this->paymentSchedule->parameters['schedule'];

        if (empty($scheduleArray)) {
            return '';
        }

        $combinedHtml = '';
        $totalSchedules = count($scheduleArray);

        foreach ($scheduleArray as $index => $scheduleItem) {
            $qrHtml = $this->generateQrForScheduleItem($scheduleItem, $index + 1, $totalSchedules);
            if (!empty($qrHtml)) {
                $combinedHtml .= $qrHtml;

                // Add page break between QR codes (except for the last one)
                if ($index < $totalSchedules - 1) {
                    $combinedHtml .= '<div style="page-break-after: always;"></div>';
                }
            }
        }

        return htmlspecialchars($combinedHtml, ENT_QUOTES, 'UTF-8');
    }

    private function generateQrForScheduleItem($scheduleItem, $currentNumber, $totalSchedules)
    {
        try {
            // Calculate the amount for this specific schedule item
            $amount = $this->calculateScheduleAmount($scheduleItem);

            // Create a new instance of QrBill
            $qrBill = QrBill\QrBill::create();

            // Add creditor information (same as original)
            $qrBill->setCreditor(
                QrBill\DataGroup\Element\CombinedAddress::create(
                    $this->company->present()->name(),
                    $this->company->present()->address1(),
                    $this->company->present()->getCompanyCityState(),
                    'CH'
                )
            );

            $qrBill->setCreditorInformation(
                QrBill\DataGroup\Element\CreditorInformation::create(
                    $this->company->present()->qr_iban() ?: ''
                )
            );

            // Add debtor information (same as original)
            $qrBill->setUltimateDebtor(
                QrBill\DataGroup\Element\StructuredAddress::createWithStreet(
                    substr($this->client->present()->name(), 0, 70),
                    $this->client->address1 ? substr($this->client->address1, 0, 70) : ' ',
                    $this->client->address2 ? substr($this->client->address2, 0, 16) : ' ',
                    $this->client->postal_code ? substr($this->client->postal_code, 0, 16) : ' ',
                    $this->client->city ? substr($this->client->city, 0, 35) : ' ',
                    'CH'
                )
            );

            // Add payment amount information for this schedule
            $qrBill->setPaymentAmountInformation(
                QrBill\DataGroup\Element\PaymentAmountInformation::create(
                    'CHF',
                    $amount
                )
            );

            // Add payment reference with schedule identifier
            $referenceNumber = $this->generateScheduleReference($scheduleItem, $currentNumber);

            if (strlen($this->company->present()->besr_id()) > 1 && $referenceNumber) {
                $qrBill->setPaymentReference(
                    QrBill\DataGroup\Element\PaymentReference::create(
                        QrBill\DataGroup\Element\PaymentReference::TYPE_QR,
                        $referenceNumber
                    )
                );
            } else {
                $qrBill->setPaymentReference(
                    QrBill\DataGroup\Element\PaymentReference::create(
                        QrBill\DataGroup\Element\PaymentReference::TYPE_NON
                    )
                );
            }

            // Add additional information with schedule details
            $additionalInfo = $this->generateAdditionalInfo($scheduleItem, $currentNumber, $totalSchedules);
            $qrBill->setAdditionalInformation(
                QrBill\DataGroup\Element\AdditionalInformation::create($additionalInfo)
            );

            // Generate the HTML output
            $output = new QrBill\PaymentPart\Output\HtmlOutput\HtmlOutput($qrBill, $this->resolveLanguage());

            return $output
                ->setPrintable(false)
                ->getPaymentPart();

        } catch (\Exception $e) {
            return '';
        }
    }

    private function calculateScheduleAmount($scheduleItem)
    {
        if ($scheduleItem['is_amount']) {
            // Fixed amount
            return (float) $scheduleItem['amount'];
        } else {
            // Percentage of total invoice amount
            $percentage = (float) $scheduleItem['amount'];
            return round(($percentage / 100) * $this->invoice->amount, 2);
        }
    }

    private function generateScheduleReference($scheduleItem, $currentNumber)
    {
        if (strlen($this->company->present()->besr_id()) < 1) {
            return null;
        }

        // Generate a reference number that includes the schedule number
        if (stripos($this->invoice->number, "Live") === 0) {
            $invoice_number = "123456789" . str_pad($currentNumber, 2, '0', STR_PAD_LEFT);
        } else {
            $tempInvoiceNumber = $this->invoice->number;
            $tempInvoiceNumber = preg_replace('/[^A-Za-z0-9]/', '', $tempInvoiceNumber);

            $calcInvoiceNumber = "";
            $array = str_split($tempInvoiceNumber);
            foreach ($array as $char) {
                if (is_numeric($char)) {
                    // Keep numeric characters
                } else {
                    if ($char) {
                        $char = strtolower($char);
                        $char = ord($char) - 96;
                    } else {
                        $char = 0;
                    }
                }
                $calcInvoiceNumber .= $char;
            }

            // Append schedule number to make reference unique
            $invoice_number = $calcInvoiceNumber . str_pad($currentNumber, 2, '0', STR_PAD_LEFT);
        }

        return QrBill\Reference\QrPaymentReferenceGenerator::generate(
            $this->company->present()->besr_id(),
            $invoice_number
        );
    }

    private function generateAdditionalInfo($scheduleItem, $currentNumber, $totalSchedules)
    {
        $baseInfo = $this->invoice->public_notes
            ? strip_tags($this->invoice->public_notes)
            : ctrans('texts.invoice_number_placeholder', ['invoice' => $this->invoice->number]);

        $scheduleInfo = " - Zahlung {$currentNumber}/{$totalSchedules} - Fällig: " . date('d.m.Y', strtotime($scheduleItem['date']));

        $combinedInfo = $baseInfo . $scheduleInfo;

        // Limit to 139 characters as per Swiss QR specification
        return substr($combinedInfo, 0, 139);
    }

    private function resolveLanguage(): string
    {
        $language = $this->client->locale() ?: 'en';

        switch ($language) {
            case 'de':
                return 'de';
            case 'en':
            case 'en_GB':
                return 'en';
            case 'it':
                return 'it';
            case 'fr':
            case 'fr_CA':
            case 'fr_CH':
                return 'fr';
            default:
                return 'en';
        }
    }
}

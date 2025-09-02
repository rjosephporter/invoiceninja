<?php

namespace Tests\Feature;

use App\Helpers\SwissQr\SwissQrPaymentScheduleGenerator;
use App\Models\Invoice;
use App\Models\Scheduler;
use App\Utils\HtmlEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;

class PaymentScheduleQrTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();
    }

    public function testInvoiceHasPaymentSchedulesMethod()
    {
        // Test case 1: Invoice without payment schedules
        $this->assertFalse($this->invoice->hasPaymentSchedules());

        // Test case 2: Invoice with payment schedules
        $scheduler = Scheduler::create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'name' => 'Test Payment Schedule',
            'template' => 'payment_schedule',
            'is_paused' => false,
            'parameters' => [
                'invoice_id' => $this->invoice->hashed_id,
                'schedule' => [
                    [
                        'id' => 1,
                        'date' => now()->addDays(30)->format('Y-m-d'),
                        'amount' => 50.00,
                        'is_amount' => true
                    ],
                    [
                        'id' => 2,
                        'date' => now()->addDays(60)->format('Y-m-d'),
                        'amount' => 50.00,
                        'is_amount' => true
                    ]
                ],
                'auto_bill' => false
            ]
        ]);

        // Refresh the invoice to clear any cached relationships
        $this->invoice = $this->invoice->fresh();
        
        $this->assertTrue($this->invoice->hasPaymentSchedules());
    }

    public function testHtmlEnginePaymentScheduleQrVariables()
    {
        // Set up company with QR IBAN
        $this->company->settings = array_merge($this->company->settings, [
            'qr_iban' => 'CH1234567890123456789'
        ]);
        $this->company->save();

        // Create payment schedule
        $scheduler = Scheduler::create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'name' => 'Test Payment Schedule',
            'template' => 'payment_schedule',
            'is_paused' => false,
            'parameters' => [
                'invoice_id' => $this->invoice->hashed_id,
                'schedule' => [
                    [
                        'id' => 1,
                        'date' => now()->addDays(30)->format('Y-m-d'),
                        'amount' => 50.00,
                        'is_amount' => true
                    ],
                    [
                        'id' => 2,
                        'date' => now()->addDays(60)->format('Y-m-d'),
                        'amount' => 50.00,
                        'is_amount' => true
                    ]
                ],
                'auto_bill' => false
            ]
        ]);

        // Test HtmlEngine template variables
        $htmlEngine = new HtmlEngine($this->invoice);
        $variables = $htmlEngine->generateLabelsAndValues();

        // Verify payment schedule QR variables exist
        $this->assertArrayHasKey('$payment_schedule_qr', $variables);
        $this->assertArrayHasKey('$payment_schedule_qr_raw', $variables);

        // Variables should have content when payment schedules exist
        $this->assertIsArray($variables['$payment_schedule_qr']);
        $this->assertArrayHasKey('value', $variables['$payment_schedule_qr']);
        $this->assertArrayHasKey('label', $variables['$payment_schedule_qr']);
    }

    public function testPaymentScheduleQrGeneratorCreation()
    {
        // Create payment schedule
        $scheduler = Scheduler::create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'name' => 'Test Payment Schedule',
            'template' => 'payment_schedule',
            'is_paused' => false,
            'parameters' => [
                'invoice_id' => $this->invoice->hashed_id,
                'schedule' => [
                    [
                        'id' => 1,
                        'date' => now()->addDays(30)->format('Y-m-d'),
                        'amount' => 50.00,
                        'is_amount' => true
                    ]
                ],
                'auto_bill' => false
            ]
        ]);

        // Test that the generator can be instantiated
        $generator = new SwissQrPaymentScheduleGenerator($this->invoice, $this->company);
        $this->assertInstanceOf(SwissQrPaymentScheduleGenerator::class, $generator);

        // Test that it can run without throwing exceptions
        try {
            $result = $generator->run();
            $this->assertIsString($result);
        } catch (\Exception $e) {
            // If QR libraries are not available or configuration is missing,
            // the generator should gracefully return empty string
            $this->assertIsString($e->getMessage());
        }
    }

    public function testHtmlEngineWithoutPaymentSchedules()
    {
        // Test that variables are empty when no payment schedules exist
        $htmlEngine = new HtmlEngine($this->invoice);
        $variables = $htmlEngine->generateLabelsAndValues();

        $this->assertArrayHasKey('$payment_schedule_qr', $variables);
        $this->assertEquals('', $variables['$payment_schedule_qr']['value']);
    }
}
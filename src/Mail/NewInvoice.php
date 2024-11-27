<?php

namespace Spark\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;

class NewInvoice extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * The available invoice.
     *
     * @var \Laravel\Cashier\Invoice
     */
    public $invoice;

    /**
     * The billable model instance.
     *
     * @var \Spark\Billable
     */
    private $billable;

    /**
     * Create a new message instance.
     *
     * @param  mixed  $billable
     * @param  \Laravel\Cashier\Invoice  $invoice
     * @return void
     */
    public function __construct($billable, $invoice)
    {
        $this->invoice = $invoice;
        $this->billable = $billable;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $invoiceData = array_merge([
            'vendor' => 'Laravel',
            'product' => '',
            'street' => '',
            'location' => '',
            'vat' => new HtmlString(nl2br(e($this->billable->extra_billing_information))),
        ], config('spark.invoice_data'));

        $filename = $invoiceData['product'].'_'.$this->invoice->date()->month.'_'.$this->invoice->date()->year;

        return $this->markdown('spark::mail.invoice')
            ->subject(__('Your :invoiceName invoice is now available!', ['invoiceName' => $this->invoice->date()->format('F Y')]))
            ->attachData($this->invoice->pdf($invoiceData), $filename.'.pdf');
    }
}

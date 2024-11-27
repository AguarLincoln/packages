<?php

namespace Spark\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;

class DownloadInvoiceController
{
    use RetrievesBillableModels;

    /**
     * Download the given invoice.
     *
     * @param  string  $type
     * @param  string  $id
     * @param  string  $invoiceId
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function __invoke(Request $request, $type, $id, $invoiceId)
    {
        $billable = $this->billable($type, $id);

        $invoiceData = array_merge([
            'vendor' => 'Laravel',
            'product' => '',
            'street' => '',
            'location' => '',
            'vat' => new HtmlString(nl2br(e($billable->extra_billing_information))),
        ], config('spark.invoice_data'));

        return $billable->downloadInvoice($invoiceId, $invoiceData);
    }
}

<?php

namespace Spark\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UpdateInvoiceEmailsController
{
    use RetrievesBillableModels;

    /**
     * Update the invoice emails for the given billable.
     *
     * @param  \Illuminate\Http\Request
     * @return void
     */
    public function __invoke(Request $request)
    {
        $emails = array_map(function ($email) {
            return trim($email);
        }, explode(',', $request->invoice_emails));

        if (validator($emails, ['*' => 'email'])->fails()) {
            throw ValidationException::withMessages([
                'emails' => __('The invoice emails must be valid email addresses.'),
            ]);
        }

        if (count($emails) > 3) {
            throw ValidationException::withMessages([
                'emails' => __('Please provide a maximum of three invoice emails addresses.'),
            ]);
        }

        $billable = $this->billable();

        $emails = $billable->hasCast('invoice_emails') ? $emails : json_encode($emails);

        $billable->forceFill([
            'invoice_emails' => $emails,
        ])->save();

        session(['spark.flash.success' => __('Invoice emails updated successfully.')]);
    }
}

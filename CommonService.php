<?php

namespace App\Services;

use PDF;
use Exception;
use Carbon\Carbon;
use App\Model\Common\Role;
use App\Mail\InvoicePaidEmail;
use App\Model\Common\Document;
use App\Model\Common\Permission;
use App\Model\Contractor\Invoice;
use App\Model\Contractor\Estimate;
use Illuminate\Support\Facades\DB;
use App\Mail\InvoicePaidClientMail;
use App\Services\Common\MyResponce;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoicePartiallyPaidEmail;
use Illuminate\Support\Facades\Storage;
use App\Model\Contractor\InvoicePayment;
use App\Model\Contractor\InvoiceProfile;
use App\Services\Common\DocumentService;
use App\Model\Contractor\InvoicePaymentHistory;
use App\Services\Common\Contracts\DocumentServiceInterface;

class CommonService
{
    protected $documentService;

    public function __construct(DocumentServiceInterface $documentService)
    {
        $this->documentService = $documentService;
    }

    public static function invoicePDFSaveS3($id)
    {
        $path = storage_path('/fonts');
        if (!File::isDirectory($path)) {
            File::makeDirectory($path, 0777, true, true);
        }

        $filename = 'invoices/invoice-' . $id . '.pdf';
        $invoice = Invoice::where('id', $id)
            ->with('invoiceItems', 'project', 'project.client')
            ->with(['invoicePayment' => function ($query) {
                $query->whereHas('invoicePaymentHistories', function ($q) {
                    $q->where('status', 'paid');
                });
                $query->with(['invoicePaymentHistories' => function ($query1) {
                    $query1->where('status', 'paid');

                    $query1->select('id', 'invoice_payment_id', 'paid_amount', 'notes', 'payment_date', 'payment_type', 'status')->orderBy('id', 'asc')
                        ->with('documents');
                }])
                    ->select('id', 'payment_id', 'invoice_id', 'paid_amount');
            }])
            ->first();

        if (!$invoice->file_path) {
            $invoice->update(['file_path' => $filename]);
        }

        $invoice->paid_at = $invoice->paid_at ? Carbon::parse($invoice->paid_at)->setTimezone($invoice->contractor->timezone)->format('m/d/Y h:i A') : null;

        $invoiceProfile = InvoiceProfile::where('contractor_id', $invoice->contractor_id)->first();
        $invoiceProfile->company_logo_url = $invoiceProfile->company_logo ? Storage::disk('public_s3')->url($invoiceProfile->company_logo) : null;
        if (!empty($invoiceProfile->company_logo_url)) {
            $imageData = file_get_contents($invoiceProfile->company_logo_url);
            $invoiceProfile->base64Image_company_logo_url = base64_encode($imageData);
        }
        $pdf = PDF::loadView('invoice.invoice', ['invoice' => $invoice, 'invoiceProfile' => $invoiceProfile]);

        return Storage::disk('s3')->put($filename, $pdf->output(), 'private');
        // return $pdf->stream($filename . ".pdf");
        // return view('invoice.invoice')->with(['invoice' => $invoice, 'invoiceProfile' => $invoiceProfile]);
    }

    public static function estimatePDFSaveS3($id)
    {
        $path = storage_path('/fonts');
        if (!File::isDirectory($path)) {
            File::makeDirectory($path, 0777, true, true);
        }

        $filename = 'estimates/estimate-' . $id . '.pdf';
        $estimate = Estimate::where('id', $id)
            ->with('estimateItems', 'client')
            ->first();

        if (!$estimate->file_path) {
            $estimate->update(['file_path' => $filename]);
        }

        $estimate->actioned_at = $estimate->actioned_at ? Carbon::parse($estimate->actioned_at)->setTimezone($estimate->contractor->timezone)->format('m/d/Y h:i A') : null;
        $invoiceProfile = InvoiceProfile::where('contractor_id', $estimate->contractor_id)->first();
        $invoiceProfile->company_logo_url = $invoiceProfile->company_logo ? Storage::disk('public_s3')->url($invoiceProfile->company_logo) : null;
        PDF::setOptions(['isPhpEnabled' => true]);
        if (!empty($invoiceProfile->company_logo_url)) {
            $imageData = file_get_contents($invoiceProfile->company_logo_url);
            $invoiceProfile->base64Image_company_logo_url = base64_encode($imageData);
        }
        $pdf = PDF::loadView('invoice.estimate', ['estimate' => $estimate, 'invoiceProfile' => $invoiceProfile]);

        return Storage::disk('s3')->put($filename, $pdf->output(), 'private');
        // return $pdf->stream($filename . ".pdf");
        // return view('invoice.invoice')->with(['invoice' => $invoice, 'invoiceProfile' => $invoiceProfile]);
    }

    public static function invoiceProfile()
    {
        return InvoiceProfile::where('contractor_id', auth('api')->user()->getContractorId())->first();
    }

    public static function createDefaultRoles($contractor_id)
    {

        if (!CommonService::roleExists('super-admin', $contractor_id)) {
            // Super Admin Role Create
            $superAdmin = Role::create([
                'contractor_id' => $contractor_id,
                'name' => 'Super Admin',
                'slug' => 'super admin',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $permissions = ['view-project', 'create-project', 'edit-project', 'delete-project', 'view-list', 'create-list', 'edit-list', 'delete-list', 'profile-invoice', 'view-invoice', 'create-invoice', 'edit-invoice', 'delete-invoice', 'invoice-payment-record', 'view-estimate', 'create-estimate', 'edit-estimate', 'delete-estimate', 'view-item', 'create-item', 'edit-item', 'delete-item', 'view-crew', 'create-crew', 'edit-crew', 'delete-crew', 'invite-crew', 'view-client', 'create-client', 'edit-client', 'delete-client', 'view-task', 'create-task', 'edit-task', 'delete-task', 'assign-task', 'view-note', 'create-note', 'edit-note', 'delete-note', 'view-expense', 'create-expense', 'edit-expense', 'delete-expense', 'view-time-card', 'create-time-card', 'edit-time-card', 'delete-time-card', 'view-quotes', 'view-reports', 'clock-in-out', 'manage-budget', 'view-distributor', 'create-distributor', 'edit-distributor', 'delete-distributor'];

            $superAdminPermission = Permission::whereIn('slug', $permissions)->pluck('id')->toArray();
            $superAdmin->permissions()->attach($superAdminPermission);
        }

        if (!CommonService::roleExists('admin', $contractor_id)) {
            // Admin Role Create
            $admin = Role::create([
                'contractor_id' => $contractor_id,
                'name' => 'Admin',
                'slug' => 'admin',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $permissions = ['view-project', 'create-project', 'edit-project', 'delete-project', 'view-list', 'create-list', 'edit-list', 'delete-list', 'profile-invoice', 'view-invoice', 'create-invoice', 'edit-invoice', 'delete-invoice', 'invoice-payment-record', 'view-estimate', 'create-estimate', 'edit-estimate', 'delete-estimate', 'view-item', 'create-item', 'edit-item', 'delete-item', 'view-crew', 'create-crew', 'edit-crew', 'delete-crew', 'invite-crew', 'view-client', 'create-client', 'edit-client', 'delete-client', 'view-task', 'create-task', 'edit-task', 'delete-task', 'assign-task', 'view-note', 'create-note', 'edit-note', 'delete-note', 'view-expense', 'create-expense', 'edit-expense', 'delete-expense', 'view-time-card', 'create-time-card', 'edit-time-card', 'delete-time-card', 'view-quotes', 'view-reports', 'clock-in-out', 'manage-budget', 'view-distributor', 'create-distributor', 'edit-distributor', 'delete-distributor'];

            $adminPermissions = Permission::whereIn('slug', $permissions)->pluck('id')->toArray();
            $admin->permissions()->attach($adminPermissions);
        }

        // Manager Role Create
        if (!CommonService::roleExists('manager', $contractor_id)) {
            $manager = Role::create([
                'contractor_id' => $contractor_id,
                'name' => 'Manager',
                'slug' => 'manager',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $permissions = ['view-project', 'view-invoice', 'create-invoice', 'edit-invoice', 'view-crew', 'create-crew', 'edit-crew', 'delete-crew', 'invite-crew', 'view-task', 'create-task', 'edit-task', 'assign-task', 'view-note', 'create-note', 'edit-note', 'delete-note', 'view-time-card', 'create-time-card', 'edit-time-card', 'delete-time-card'];

            $managerPermission = Permission::whereIn('slug', $permissions)->pluck('id')->toArray();
            $manager->permissions()->attach($managerPermission);
        }

        if (!CommonService::roleExists('crew', $contractor_id)) {
            $crew = Role::create([
                'contractor_id' => $contractor_id,
                'name' => 'Crew',
                'slug' => 'crew',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $permissions = ['view-project', 'view-invoice', 'view-crew', 'view-task', 'edit-task', 'view-note', 'create-note', 'edit-note', 'view-time-card', 'create-time-card', 'clock-in-out', 'view-expense', 'create-expense', 'edit-expense'];

            $crewPermission = Permission::whereIn('slug', $permissions)->pluck('id')->toArray();
            $crew->permissions()->attach($crewPermission);
        }
    }

    public static function roleExists($slug, $contractor_id)
    {
        $role = Role::where('slug', $slug)->where('contractor_id', $contractor_id)->first();
        return ($role != null);
    }

    public static function getTimeZoneByLatLong($lat, $long)
    {
        $client = new \GuzzleHttp\Client();
        $timezoneResponse = $client->get("https://api.wheretheiss.at/v1/coordinates/" . $lat . "," . $long);
        $timezone = json_decode($timezoneResponse->getBody(), true);
        return $timezone['timezone_id'];
    }

    public static function recordPayment($paymentIntent)
    {
        $invoice = Invoice::findOrFail($paymentIntent['metadata']['invoice_id']);
        DB::beginTransaction();
        $myResponce = new MyResponce();
        try {
            $invoicePayment = InvoicePayment::where('invoice_id', $invoice->id)->first();
            $payment = InvoicePayment::where('contractor_id', $invoice->contractor_id)->where('invoice_id', $invoice->id)->first();

            $paymentId = $payment->payment_id ?? 0;
            if (empty($payment)) {
                $paymentId = InvoicePayment::where('contractor_id', $invoice->contractor_id)->max('payment_id');
                $paymentId = $paymentId + 1;
            }

            $paidAmount = $paymentIntent['amount_received'] / 100;
            // Check payment exist or not
            if (empty($invoicePayment)) {
                $invoicePayment = InvoicePayment::create([
                    'contractor_id' => $invoice->contractor_id,
                    'payment_id' => $paymentId,
                    'invoice_id' => $invoice->id,
                    'client_id' => $invoice->client_id,
                    'project_id' => $invoice->project_id,
                    'invoice_number' => $invoice->invoice_id_prefix . $invoice->invoice_id,
                    'invoice_amount' => $invoice->total,
                    'paid_amount' => $paidAmount,
                    'payment_date' => Carbon::now()->format('Y-m-d'),
                    'status' => doubleval($paidAmount) >= $invoice->total ? InvoicePayment::STATUS_PAID : InvoicePayment::STATUS_PARTIALLY_PAID,
                ]);
            }

            $checkPaymentRecord = InvoicePaymentHistory::where('payment_intent_id', $paymentIntent['id'])->first();
            if (!empty($checkPaymentRecord)) {
                return $myResponce->successResp('', [], '', 201);
            }

            $invoicePaymentHistory = InvoicePaymentHistory::create([
                'invoice_payment_id' => $invoicePayment->id,
                'paid_amount' => $paidAmount,
                'notes' => 'Payment by stripe',
                'payment_date' => Carbon::now()->format('Y-m-d'),
                'payment_type' => 'stripe',
                'payment_intent_id' => $paymentIntent['id']
            ]);

            $invoicePaymentHistories = InvoicePaymentHistory::where('invoice_payment_id', $invoicePayment->id)
                ->orderBy('payment_date', 'desc')
                ->get();

            $invoice_paid_amount = number_format($invoicePaymentHistories->sum('paid_amount'), 2, '.', '');
            $invoice_total_amount = number_format($invoicePayment->invoice_amount, 2, '.', '');
            // $invoicePayment->update([
            //     'payment_date' => $invoicePaymentHistories->first()->payment_date,
            //     'paid_amount' => $invoicePaymentHistories->sum('paid_amount'),
            //     'status' => $invoice_paid_amount >= $invoice_total_amount ? InvoicePayment::STATUS_PAID : InvoicePayment::STATUS_PARTIALLY_PAID,
            // ]);

            $invoiceStatus = $invoice_paid_amount >= $invoice_total_amount ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIALLY_PAID;

            if (Carbon::parse($invoice->invoice_date)->addDays($invoice->invoice_due_days)->format('Y-m-d') < Carbon::now()->format('Y-m-d') && $invoiceStatus !== Invoice::STATUS_PAID) {
                $invoiceStatus = Invoice::STATUS_OVERDUE;
            }

            // $invoice->update([
            //     'status' => $invoiceStatus,
            //     'paid_at' => $invoiceStatus === Invoice::STATUS_PAID ? Carbon::now() : null,
            //     'due_amount' => $invoice->total - $invoicePaymentHistories->sum('paid_amount'),
            // ]);

            // CommonService::invoicePDFSaveS3($invoice->id);

            // if (!$invoice->is_manual_send) {
            //     $invoiceProfile = InvoiceProfile::where('contractor_id', $invoice->contractor_id)->first();
            //     $companyLogo = $invoiceProfile->company_logo ? Storage::disk('public_s3')->url($invoiceProfile->company_logo) : null;
            //     $emailTo = explode(',', $invoice->send_to);
            //     $emailCC = $invoice->send_cc ? explode(',', $invoice->send_cc) : [];

            //     if ($invoice_paid_amount >= $invoice_total_amount) {
            //         Mail::to($emailTo)->cc($emailCC)->send(new InvoicePaidClientMail($invoice, $invoice->contractor, $companyLogo, $invoiceProfile->company_name));
            //         Mail::to($invoice->contractor->email)->send(new InvoicePaidEmail($invoice, $invoice->contractor, $companyLogo, $invoiceProfile->company_name));
            //     } else {
            //         Mail::to($emailTo)->cc($emailCC)->send(new InvoicePartiallyPaidEmail($invoice, $invoice->contractor, $companyLogo, $invoiceProfile->company_name));
            //     }
            // }

            DB::commit();
            return $myResponce->successResp('', [], '', 201);
        } catch (Exception $e) {
            Log::channel('requestlogs')->error($e);
            DB::rollback();
            return $myResponce->errorResp('Something went wrong!', 500);
        }
    }

    public static function recordStripeManualPayment($paymentIntent, $request)
    {
        // dd($request->all());
        $invoice = Invoice::findOrFail($paymentIntent['metadata']['invoice_id']);
        DB::beginTransaction();
        $myResponce = new MyResponce();

        try {
            $invoicePayment = InvoicePayment::where('invoice_id', $invoice->id)->latest()->first();
            $payment = InvoicePayment::where('contractor_id', $invoice->contractor_id)->where('invoice_id', $invoice->id)->latest()->first();

            $paymentId = $payment->payment_id ?? 0;
            if (empty($payment)) {
                $paymentId = InvoicePayment::where('contractor_id', $invoice->contractor_id)->max('payment_id');
                $paymentId = $paymentId + 1;
            }

            $invoicePaymentData =  InvoicePayment::where('payment_id', $paymentId)->latest()->first();
            if ($invoicePaymentData) {
                // $paymentHistoryData = InvoicePaymentHistory::where([['invoice_payment_id', '=', $invoicePaymentData->id], ['manual_payment_object', '!=', null]])->first();
                $paymentHistoryData = InvoicePaymentHistory::where('invoice_payment_id', '=', $invoicePaymentData->id)
                    ->where('manual_payment_object', '!=', null)->first();
            }

            // if ($paymentHistoryData) {
            //     $manualPaymentData =  json_decode($paymentHistoryData->manual_payment_object, true);
            // }

            $paidAmount = $paymentIntent['amount_received'] / 100;
            //doubleval($paidAmount) > 0 ? (doubleval($paidAmount) >= $invoice->total ? InvoicePayment::STATUS_PAID : InvoicePayment::STATUS_PARTIALLY_PAID) : 'pending',
            // dd(auth('api')->user()->getContractorId());
            // Check payment exist or not
            if (empty($invoicePayment)) {
                $invoicePayment = InvoicePayment::create([
                    'contractor_id' => auth('api')->user()->id,
                    'payment_id' => $paymentId,
                    'invoice_id' => $invoice->id,
                    'client_id' => $invoice->client_id,
                    'project_id' => $invoice->project_id,
                    'invoice_number' => $invoice->invoice_id_prefix . $invoice->invoice_id,
                    'invoice_amount' => $invoice->total,
                    'paid_amount' => $paidAmount,
                    'payment_date' => Carbon::now()->format('Y-m-d'),
                    'status' => doubleval($paidAmount) >= $invoice->total ? InvoicePayment::STATUS_PAID : InvoicePayment::STATUS_PARTIALLY_PAID,
                    'created_by' => auth('api')->user()->id,
                ]);
            }

            $checkPaymentRecord = InvoicePaymentHistory::where('payment_intent_id', $paymentIntent['id'])->first();
            if (!empty($checkPaymentRecord)) {
                // dd('dfg');
                return $myResponce->successResp('', [], '', 201);
            }
            // dd('sdfs');
            $invoicePaymentHistory = InvoicePaymentHistory::create([
                'invoice_payment_id' => $invoicePayment->id,
                'paid_amount' => $paidAmount,
                'notes' => $request->notes,
                'payment_date' => Carbon::now()->format('Y-m-d'),
                'payment_type' => 'stripe',
                'payment_intent_id' => $paymentIntent['id']
            ]);
            // $invoicePaymentHistories = InvoicePaymentHistory::where('invoice_payment_id', $invoicePayment->id)
            //     ->orderBy('payment_date', 'desc')
            //     ->get();
            // dd($invoicePaymentHistories);
            // if (!empty($checkPaymentRecord)) {
            //     return $myResponce->successResp('', [], '', 201);
            // }

            // dd($invoicePaymentData, $paymentHistoryData, $checkPaymentRecord, $paidAmount);
            // $invoicePaymentHistory = InvoicePaymentHistory::where('id', $paymentHistoryData['id'])->update([
            //     'invoice_payment_id' => $invoicePayment['id'],
            //     'paid_amount' => $paidAmount,
            //     'payment_intent_id' => $paymentIntent['id'],
            //     'manual_payment_object' => null
            // ]);
            // dd('fdv');

            if ($request['attachments'] === 'null') {
                $request['attachments'] = [];
            }

            if (!empty($request['attachments']) && count($request['attachments']) > 0) {
                // $attachments = DocumentService::invoiceDocument($request, $invoicePaymentHistory->id, 'invoice payment history');
                $attachments = false;
                try {
                    $document = new Document();
                    foreach ($request->file('attachments') as $key => $file) {
                        $name = time() . $file->getClientOriginalName();
                        $filePath = 'invoices-document/' . $name;
                        Storage::disk('s3')->put($filePath, file_get_contents($file), 'private');
                        $document->create([
                            'id' =>  $invoicePaymentHistory->id,
                            'type' => 'invoice payment history',
                            'filepath' => $filePath,
                            'name' => $file->getClientOriginalName(),
                            'size' => $file->getSize(),
                        ]);
                    }
                    $attachments =  true;
                } catch (Exception $e) {
                    Log::channel('requestlogs')->error($e);
                    $attachments = false;
                }
                if ($attachments === false) {
                    return $myResponce->errorResp('Something went wrong!', 500);
                }
            }
            // InvoicePaymentHistory::where('id', $paymentHistoryData['id'])->update([
            //     'manual_payment_object' => null
            // ]);


            $invoicePaymentHistories = InvoicePaymentHistory::where('invoice_payment_id', $invoicePayment->id)
                ->orderBy('payment_date', 'desc')
                ->get();

            $invoice_paid_amount = number_format($invoicePaymentHistories->sum('paid_amount'), 2, '.', '');
            $invoice_total_amount = number_format($invoicePayment->invoice_amount, 2, '.', '');
            // $invoicePayment->update([
            //     'payment_date' => $invoicePaymentHistories->first()->payment_date,
            //     'paid_amount' => $invoicePaymentHistories->sum('paid_amount'),
            //     'status' => $invoice_paid_amount >= $invoice_total_amount ? InvoicePayment::STATUS_PAID : InvoicePayment::STATUS_PARTIALLY_PAID,
            // ]);

            $invoiceStatus =  $invoice_paid_amount >= $invoice_total_amount ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIALLY_PAID;

            if (Carbon::parse($invoice->invoice_date)->addDays($invoice->invoice_due_days)->format('Y-m-d') < Carbon::now()->format('Y-m-d') && $invoiceStatus !== Invoice::STATUS_PAID) {
                $invoiceStatus = Invoice::STATUS_OVERDUE;
            }

            // $invoice->update([
            //     'status' => $invoiceStatus,
            //     'paid_at' => $invoiceStatus === Invoice::STATUS_PAID ? Carbon::now() : null,
            //     'due_amount' => $invoice->total - $invoicePaymentHistories->sum('paid_amount'),
            // ]);

            // CommonService::invoicePDFSaveS3($invoice->id);

            // if (!$invoice->is_manual_send) {
            //     $invoiceProfile = InvoiceProfile::where('contractor_id', $invoice->contractor_id)->first();
            //     $companyLogo = $invoiceProfile->company_logo ? Storage::disk('public_s3')->url($invoiceProfile->company_logo) : null;
            //     $emailTo = explode(',', $invoice->send_to);
            //     $emailCC = $invoice->send_cc ? explode(',', $invoice->send_cc) : [];

            //     if ($invoice_paid_amount >= $invoice_total_amount) {
            //         Mail::to($emailTo)->cc($emailCC)->send(new InvoicePaidClientMail($invoice, $invoice->contractor, $companyLogo, $invoiceProfile->company_name));
            //         Mail::to($invoice->contractor->email)->send(new InvoicePaidEmail($invoice, $invoice->contractor, $companyLogo, $invoiceProfile->company_name));
            //     } else {
            //         Mail::to($emailTo)->cc($emailCC)->send(new InvoicePartiallyPaidEmail($invoice, $invoice->contractor, $companyLogo, $invoiceProfile->company_name));
            //     }
            // }
            // dd('ffd');
            DB::commit();
            return $myResponce->successResp('', [], '', 201);
        } catch (Exception $e) {
            // dd('');
            Log::channel('requestlogs')->error($e);
            DB::rollback();
            return $myResponce->errorResp('Something went wrong!', 500);
        }
    }
}

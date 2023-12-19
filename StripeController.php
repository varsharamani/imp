<?php

namespace App\Http\Controllers\API\Common;


use Carbon\Carbon;
use App\Model\Common\Card;
use App\Model\Common\Role;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use App\Mail\InvoicePaidEmail;
use App\Model\Contractor\Crew;
use App\Services\CommonService;
use App\Services\StripeService;
use App\Model\Contractor\Invoice;
use App\Model\Common\Subscription;
use Illuminate\Support\Facades\DB;
use App\Mail\InvoicePaidClientMail;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoicePartiallyPaidEmail;
use Illuminate\Support\Facades\Storage;
use App\Model\Contractor\InvoicePayment;
use App\Model\Contractor\InvoiceProfile;
use Illuminate\Support\Facades\Validator;
use App\Model\Distributor\DistributorCompany;
use App\Model\Contractor\InvoicePaymentHistory;
use App\Services\Common\Contracts\MyResponceServiceInterface;
use App\Services\Common\Contracts\StripePaymentServiceInterface;

class StripeController extends Controller
{
    protected $stripePaymentService;
    protected $stripe;
    protected $myResponce;

    public function __construct(MyResponceServiceInterface $myResponce, StripePaymentServiceInterface $stripePaymentService)
    {
        $this->myResponce = $myResponce;
        $this->stripePaymentService = $stripePaymentService;
        $this->stripe = new \Stripe\StripeClient(config('app.stripe_secret'));
    }

    public function callbackStripe(Request $request)
    {
        return $this->stripePaymentService->callbackStripe($request);
    }

    public function webhookStripe()
    {
        $endpoint_secret = config('app.stripe_endpoint_secret');

        $payload = @file_get_contents('php://input');
        $sig_header = request()->header()['stripe-signature'][0];
        $event = null;
        logger(['payments' => $payload]);
        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                $endpoint_secret
            );
            $data =  $event;

            if ($data['type'] === 'payment_intent.succeeded') {
                if (isset($data['data']['object']['metadata']) && isset($data['data']['object']['metadata']['invoice_id'])) {
                    CommonService::recordPayment($data['data']['object']);
                    $paymentIntent = $data['data']['object'];
                    $invoice = Invoice::findOrFail($paymentIntent['metadata']['invoice_id']);
                    $histData =  InvoicePaymentHistory::where('payment_intent_id', $paymentIntent['id'])->first();
                    $paidAmount = $paymentIntent['amount_received'] / 100;
                    InvoicePayment::where('invoice_id', $invoice->id)->update(['paid_amount' => $paidAmount]);
                    InvoicePaymentHistory::where('payment_intent_id', $paymentIntent['id'])->update(['status' => 'paid', 'paid_amount' => $paidAmount]);
                    $invoicePayment = InvoicePayment::where('invoice_id', $invoice->id)->latest()->first();
                    $invoicePaymentHistories = InvoicePaymentHistory::where('invoice_payment_id', $invoicePayment->id)
                        ->orderBy('payment_date', 'desc')
                        ->get();
                    $invoice_paid_amount = number_format($invoicePaymentHistories->sum('paid_amount'), 2, '.', '');
                    $invoice_total_amount = number_format($invoicePayment->invoice_amount, 2, '.', '');
                    $invoicePayment->update([
                        'payment_date' => $invoicePaymentHistories->first()->payment_date,
                        'paid_amount' => $invoicePaymentHistories->sum('paid_amount'),
                        'status' => $invoice_paid_amount >= $invoice_total_amount ? InvoicePayment::STATUS_PAID : InvoicePayment::STATUS_PARTIALLY_PAID,
                    ]);

                    $invoiceStatus = $invoice_paid_amount >= $invoice_total_amount ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIALLY_PAID;

                    if (Carbon::parse($invoice->invoice_date)->addDays($invoice->invoice_due_days)->format('Y-m-d') < Carbon::now()->format('Y-m-d') && $invoiceStatus !== Invoice::STATUS_PAID) {
                        $invoiceStatus = Invoice::STATUS_OVERDUE;
                    }

                    $invoice->update([
                        'status' => $invoiceStatus,
                        'paid_at' => $invoiceStatus === Invoice::STATUS_PAID ? Carbon::now() : null,
                        'due_amount' => $invoice->total - $invoicePaymentHistories->sum('paid_amount'),
                    ]);

                    $invoiceProfile = InvoiceProfile::where('contractor_id', $invoice->contractor_id)->first();
                    $companyLogo = $invoiceProfile->company_logo ? Storage::disk('public_s3')->url($invoiceProfile->company_logo) : null;
                    $emailTo = explode(',', $invoice->send_to);
                    $emailCC = $invoice->send_cc ? explode(',', $invoice->send_cc) : [];
                    DB::commit();
                    CommonService::invoicePDFSaveS3($invoice->id);
                    if ($invoice_paid_amount >= $invoice_total_amount) {
                        Mail::to($emailTo)->cc($emailCC)->send(new InvoicePaidClientMail($invoice, $invoice->contractor, $companyLogo, $invoiceProfile->company_name));
                        Mail::to($invoice->contractor->email)->send(new InvoicePaidEmail($invoice, $invoice->contractor, $companyLogo, $invoiceProfile->company_name));
                    } else {
                        Mail::to($emailTo)->cc($emailCC)->send(new InvoicePartiallyPaidEmail($invoice, $invoice->contractor, $companyLogo, $invoiceProfile->company_name));
                    }
                }
            } else if ($data['type'] === 'payment_intent.payment_failed') {
                $paymentIntent = $data['data']['object'];
                InvoicePaymentHistory::where('payment_intent_id', $paymentIntent['id'])->update(['status' => 'failed']);
            }
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            http_response_code(400);
            exit();
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            http_response_code(400);
            exit();
        }
        http_response_code(200);
    }

    public function webhookStripeSubscription()
    {
        $endpoint_secret = config('app.stripe_default_endpoint_secret');

        $payload = @file_get_contents('php://input');
        $sig_header = request()->header()['stripe-signature'][0];
        $event = null;

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sig_header,
                $endpoint_secret
            );
            $data =  $event;

            if (in_array($data['type'], ['checkout.session.completed', 'customer.subscription.created', 'customer.subscription.deleted', 'customer.subscription.paused', 'customer.subscription.resumed', 'customer.subscription.updated'])) {
                StripeService::createSubscription($data['data']['object']);
            } else if ($data['type'] === 'customer.source.deleted') {
                Card::where('card_token_id', $data['data']['object']['id'])->delete();
                DB::commit();
            }
        } catch (\UnexpectedValueException $e) {
            // Invalid payload
            http_response_code(400);
            exit();
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            http_response_code(400);
            exit();
        }
        http_response_code(200);
    }

    public function subscriptionPlans()
    {
        $productsId = explode(',', config('app.stripe_products_id'));
        $products = $this->stripe->products->all(['ids' => $productsId])->toArray();
        $displayProducts = [];
        $productIndex = [
            'Solo' => 0,
            'Team' => 1,
            'Business' => 2
        ];

        foreach ($products['data'] as $key => $product) {
            if (in_array($product['name'], ['Solo', 'Team', 'Business']) && $product['active']) {
                $prices = $this->stripe->prices->all(['product' => $product['id']])->toArray();
                $monthlyPrice = array_values(Arr::where($prices['data'], function ($value, $key) {
                    return $value['recurring']['interval'] === 'month';
                }))[0];

                $yearlyPrice = array_values(Arr::where($prices['data'], function ($value, $key) {
                    return $value['recurring']['interval'] === 'year';
                }))[0];

                $displayProducts[] = [
                    'index' => $productIndex[$product['name']],
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'description' => $product['description'],
                    'monthly' => [
                        'id' => $monthlyPrice['id'],
                        'currency' => $monthlyPrice['currency'],
                        'product' => $monthlyPrice['product'],
                        'unit_amount' => $monthlyPrice['unit_amount'] / 100,
                        'price' => $monthlyPrice['unit_amount'] / 100,
                        'type' => $monthlyPrice['type'],
                        'interval' => $monthlyPrice['recurring']['interval'],
                    ],
                    'yearly' => [
                        'id' => $yearlyPrice['id'],
                        'currency' => $yearlyPrice['currency'],
                        'product' => $yearlyPrice['product'],
                        'unit_amount' => $yearlyPrice['unit_amount'] / 100,
                        'price' => $yearlyPrice['unit_amount'] / 12 / 100,
                        'type' => $yearlyPrice['type'],
                        'interval' => $yearlyPrice['recurring']['interval'],
                    ],
                ];
            }
        }

        $keys = array_column($displayProducts, 'index');
        array_multisort($keys, SORT_ASC, $displayProducts);

        return $this->myResponce->successResp('Plans get successfully', [], $displayProducts, 200);
    }

    public function subscriptionCheckOutLink($email, $plan_id)
    {
        $checkOutLink = StripeService::subscriptionCheckOutLink($email, $plan_id);
        return $this->myResponce->successResp('Link create successfully', [
            'check_out_url' => $checkOutLink
        ], '', 200);
    }

    public function subscriptionCheckOut($session_id)
    {
        $data = $this->stripe->checkout->sessions->retrieve($session_id, [])->toArray();
        StripeService::createSubscription($data);
        return $this->myResponce->successResp('Your account subscribe successfully', [], '', 200);
    }

    public function createPaymentLink(Request $request)
    {
        $validator = Validator::make(request()->all(), [
            'invoice_id' => 'required',
            'paid_amount' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->myResponce->errorResp($validator->errors()->first(), 500);
        }

        $invoice = Invoice::where('id', request()->invoice_id)->with('contractor')->first();
        if (empty($invoice) || empty($invoice->contractor) || !$invoice->contractor->account_id) {
            return $this->myResponce->errorResp('You’re not able to process payment because there is no linked bank account in your profile. Please set up a bank account to continue with the payment.', 500);
        }

        // if (explode('.', request()->paid_amount)[0] == 0) {
        //     request()->merge(['paid_amount' => 1]);
        // }

        $payment_intent = $this->stripe->paymentIntents->create(
            [
                'amount' => request()->paid_amount * 100,
                'currency' => 'usd',
                'automatic_payment_methods' => ['enabled' => true],
                'application_fee_amount' => round((request()->paid_amount) * floatval(config('app.stripe_invoice_fees_percentage')) / 100, 2) * 100,
                'metadata' => [
                    'invoice_id' => request()->invoice_id
                ]
            ],
            ['stripe_account' => $invoice->contractor->account_id]
        );
        // dd(auth('api')->user()->getContractorId());
        if (array_key_exists("attachments", $request->all())) {
            CommonService::recordStripeManualPayment($payment_intent, $request);
        } else {
            CommonService::recordPayment($payment_intent,);
        }

        return $this->myResponce->successResp('Plan subscribe successfully', [
            'client_secret' => $payment_intent->client_secret,
            'payment_intent_id' => $payment_intent->id,
            'account_id' => $invoice->contractor->account_id
        ], '', 200);
    }

    public function subscriptionDetails()
    {
        $data = Subscription::where('subscription_id', auth('api')->user()->subscription->subscription_id)->first();
        return $this->myResponce->successResp('', $data, '', 200);
    }

    public function cancelSubscription()
    {
        $subscription = $this->stripe->subscriptions->update(auth('api')->user()->subscription->subscription_id, [
            'cancel_at_period_end' => true
        ])->toArray();

        Subscription::where('subscription_id', auth('api')->user()->subscription->subscription_id)->update([
            'cancel_at' => $subscription['cancel_at'] ? Carbon::parse($subscription['cancel_at'])->format('Y-m-d H:i:s') : null,
        ]);

        return $this->subscriptionDetails();
    }

    public function reactiveSubscription()
    {
        $subscription = $this->stripe->subscriptions->update(auth('api')->user()->subscription->subscription_id, [
            'cancel_at_period_end' => false
        ])->toArray();

        Subscription::where('subscription_id', auth('api')->user()->subscription->subscription_id)->update([
            'cancel_at' => $subscription['cancel_at'] ? Carbon::parse($subscription['cancel_at'])->format('Y-m-d H:i:s') : null
        ]);

        return $this->subscriptionDetails();
    }

    public function updateSubscription()
    {
        $subscription = auth('api')->user()->subscription;


        if ($subscription->plan_interval === 'monthly') {
            if (date('Y-m-d') !== Carbon::parse($subscription->expired_at)->format('Y-m-d')) {
                return $this->myResponce->errorResp('You can update your subscription at your monthly renewal date', 500);
            }
        } elseif ($subscription->plan_interval === 'yearly') {
            if (env('APP_ENV') === 'local') {
                if ($subscription->plan_name === 'Solo') {
                    if (in_array(request()->plan_id, ['price_1NCy8hL3XNmWR9l58BcEJRng', 'price_1NCyAQL3XNmWR9l5afS3bHhK', 'price_1NCyIKL3XNmWR9l5th7MXIpR'])) {
                        return $this->myResponce->errorResp("You can't update your subscription plan at downgrade", 500);
                    }
                } elseif ($subscription->plan_name === 'Team') {
                    if (in_array(request()->plan_id, ['price_1NCy8hL3XNmWR9l58BcEJRng', 'price_1NCyAQL3XNmWR9l5afS3bHhK', 'price_1NCyIKL3XNmWR9l5th7MXIpR', 'price_1NCy8hL3XNmWR9l5oQ5AY1i3'])) {
                        return $this->myResponce->errorResp("You can't update your subscription plan at downgrade", 500);
                    }
                } elseif ($subscription->plan_name === 'Business') {
                    if (in_array(request()->plan_id, ['price_1NCy8hL3XNmWR9l58BcEJRng', 'price_1NCyAQL3XNmWR9l5afS3bHhK', 'price_1NCyIKL3XNmWR9l5th7MXIpR', 'price_1NCy8hL3XNmWR9l5oQ5AY1i3', 'price_1NCyAQL3XNmWR9l52UfrW5SW'])) {
                        return $this->myResponce->errorResp("You can't update your subscription plan at downgrade", 500);
                    }
                }
            } else if (env('APP_ENV') === 'staging') {
                if ($subscription->plan_name === 'Solo') {
                    if (in_array(request()->plan_id, ['price_1NJEc3DyrG3G8K6eFVOJZwpY', 'price_1NJEeuDyrG3G8K6eqdCHMKSn', 'price_1NJEfjDyrG3G8K6eVCSgmd7N'])) {
                        return $this->myResponce->errorResp("You can't update your subscription plan at downgrade", 500);
                    }
                } elseif ($subscription->plan_name === 'Team') {
                    if (in_array(request()->plan_id, ['price_1NJEc3DyrG3G8K6eFVOJZwpY', 'price_1NJEeuDyrG3G8K6eqdCHMKSn', 'price_1NJEfjDyrG3G8K6eVCSgmd7N', 'price_1NJEc3DyrG3G8K6ebNoc8EZd'])) {
                        return $this->myResponce->errorResp("You can't update your subscription plan at downgrade", 500);
                    }
                } elseif ($subscription->plan_name === 'Business') {
                    if (in_array(request()->plan_id, ['price_1NJEc3DyrG3G8K6eFVOJZwpY', 'price_1NJEeuDyrG3G8K6eqdCHMKSn', 'price_1NJEfjDyrG3G8K6eVCSgmd7N', 'price_1NJEc3DyrG3G8K6ebNoc8EZd', 'price_1NJEeuDyrG3G8K6eDqY9FRUe'])) {
                        return $this->myResponce->errorResp("You can't update your subscription plan at downgrade", 500);
                    }
                }
            } else if (env('APP_ENV') === 'prod') {
                if ($subscription->plan_name === 'Solo') {
                    if (in_array(request()->plan_id, ['price_1NuXd6DyrG3G8K6eCpeShk5s', 'price_1NuXd1DyrG3G8K6etCiJTXVD', 'price_1NuXcqDyrG3G8K6e5wnMXhhV'])) {
                        return $this->myResponce->errorResp("You can't update your subscription plan at downgrade", 500);
                    }
                } elseif ($subscription->plan_name === 'Team') {
                    if (in_array(request()->plan_id, ['price_1NuXd6DyrG3G8K6eCpeShk5s', 'price_1NuXd1DyrG3G8K6etCiJTXVD', 'price_1NuXcqDyrG3G8K6e5wnMXhhV', 'price_1NuXd6DyrG3G8K6eVRQjNSpL'])) {
                        return $this->myResponce->errorResp("You can't update your subscription plan at downgrade", 500);
                    }
                } elseif ($subscription->plan_name === 'Business') {
                    if (in_array(request()->plan_id, ['price_1NuXd6DyrG3G8K6eCpeShk5s', 'price_1NuXd1DyrG3G8K6etCiJTXVD', 'price_1NuXcqDyrG3G8K6e5wnMXhhV', 'price_1NuXd6DyrG3G8K6eVRQjNSpL', 'price_1NuXd1DyrG3G8K6e7ZujZP7d'])) {
                        return $this->myResponce->errorResp("You can't update your subscription plan at downgrade", 500);
                    }
                }
            }
        }



        $this->stripe->subscriptions->update(auth('api')->user()->subscription->subscription_id, [
            'items' => [
                [
                    'id' => $subscription->subscription_item_id,
                    'price' => request()->plan_id,
                    'quantity' => 1,
                ],
            ],
            'billing_cycle_anchor' => 'now',
            'proration_behavior' => 'always_invoice'
        ])->toArray();

        StripeService::createSubscription(
            [
                'subscription' => $subscription->subscription_id,
                'customer_details' => ['email' => auth('api')->user()->email],
                'customer' => $subscription->customer_id
            ]
        );

        $data = Subscription::where('subscription_id', auth('api')->user()->subscription->subscription_id)->first();
        if ((request()->plan_name === 'Business' && $data->plan_name === 'Solo' || $data->plan_name === 'Team') || (request()->plan_name === 'Team' && $data->plan_name === 'Solo')) {
            if ($data->plan_name === 'Solo') {
                $crews = Crew::where('contractor_id', auth('api')->user()->id)->wherehas('roles')->with('crewUser')->get();
                if (!empty($crews)) {
                    $rolesId = Role::where('contractor_id', auth('api')->user()->id)->pluck('id')->toArray();
                    foreach ($crews as $crew) {
                        $userId = !empty($crew->crewUser) && !empty($crew->crewUser->user) ? $crew->crewUser->user->id : 0;
                        DB::table('users_roles')->where('user_id', $userId)
                            ->whereIn('role_id', $rolesId)
                            ->where('crew_id', $crew->crew_id)
                            ->update([
                                'is_active' => 0,
                            ]);
                    }
                }
            } else if ($data->plan_name === 'Team') {
                $crews = Crew::where('contractor_id', auth('api')->user()->id)->wherehas('roles')->with('crewUser')->skip(5)->orderBy('crew_id', 'asc')->get();
                if (!empty($crews)) {
                    $rolesId = Role::where('contractor_id', auth('api')->user()->id)->pluck('id')->toArray();
                    foreach ($crews as $crew) {
                        $userId = !empty($crew->crewUser) && !empty($crew->crewUser->user) ? $crew->crewUser->user->id : 0;
                        DB::table('users_roles')->where('user_id', $userId)
                            ->whereIn('role_id', $rolesId)
                            ->where('crew_id', $crew->crew_id)
                            ->update([
                                'is_active' => 0,
                            ]);
                    }
                }
            }
        } else if ($data->plan_name === 'Business') {
            $crews = Crew::where('contractor_id', auth('api')->user()->id)->wherehas('roles')->with('crewUser')->skip(8)->orderBy('crew_id', 'asc')->get();
            if (!empty($crews)) {
                $rolesId = Role::where('contractor_id', auth('api')->user()->id)->pluck('id')->toArray();
                foreach ($crews as $crew) {
                    $userId = !empty($crew->crewUser) && !empty($crew->crewUser->user) ? $crew->crewUser->user->id : 0;
                    DB::table('users_roles')->where('user_id', $userId)
                        ->whereIn('role_id', $rolesId)
                        ->where('crew_id', $crew->crew_id)
                        ->update([
                            'is_active' => 0,
                        ]);
                }
            }
        }

        return $this->myResponce->successResp('', $data, '', 200);
    }

    public function createCustomerProtalLink()
    {
        $subscription = auth('api')->user()->subscription;
        $data = $this->stripe->billingPortal->sessions->create([
            'customer' => $subscription->customer_id,
            'return_url' => config('app.contractor_url') . 'my-account',
        ])->toArray();

        return $this->myResponce->successResp('', $data['url'], '', 200);
    }

    public function accountUpdateLink()
    {
        if (auth('api')->user()->role === 'C') {
            $accountId = auth('api')->user()->account_id;
            $returnURL = config('app.contractor_url') . 'my-account';
        } else {
            $distributorCompany =  DistributorCompany::where('company_id', auth('api')->user()->getCompanyId())->first();
            $accountId = $distributorCompany->account_id;
            $returnURL = config('app.web_url') . 'settings';
        }
        $data = $this->stripe->accountLinks->create([
            'account' => $accountId,
            'refresh_url' =>  $returnURL,
            'return_url' =>  $returnURL,
            'type' => 'account_onboarding',
        ])->toArray();
        return $this->myResponce->successResp('', $data['url'], '', 200);
    }

    public function viewAccountTransactions()
    {
        if (auth('api')->user()->role === 'C') {
            $accountId = auth('api')->user()->account_id;
        } else {
            $distributorCompany =  DistributorCompany::where('company_id', auth('api')->user()->getCompanyId())->first();
            $accountId = $distributorCompany->account_id;
        }

        $data = $this->stripe->accounts->createLoginLink(
            $accountId,
            []
        )->toArray();
        return $this->myResponce->successResp('', $data['url'], '', 200);
    }
}

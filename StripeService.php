<?php

namespace App\Services;

use App\User;
use Carbon\Carbon;
use App\Jobs\SendEmail;
use App\Model\Common\Role;
use App\Model\Contractor\Crew;
use App\Model\Common\Subscription;
use Illuminate\Support\Facades\DB;

class StripeService
{

    public static function createCustomer($user)
    {
        $stripe =  new \Stripe\StripeClient(config('app.stripe_secret'));
        $checkCustomerExist = $stripe->customers->all([
            'email' => $user->email,
            'limit' => 1
        ])->toArray();

        $customerId = !empty($checkCustomerExist['data']) ? $checkCustomerExist['data'][0]['id'] : null;
        if (empty($checkCustomerExist['data'])) {
            $customer = $stripe->customers->create([
                'name' => $user->fullname,
                'phone' => $user->phone,
                'email' => $user->email
            ]);

            $customerId = $customer['id'];
        }

        return $customerId;
    }

    public static function subscriptionCheckOutLink($email, $price_id)
    {
        $stripe =  new \Stripe\StripeClient(config('app.stripe_secret'));
        $price = $stripe->prices->retrieve($price_id, [])->toArray();
        if (empty($price)) {
            return null;
        }

        $user = User::where('email', $email)->with('subscription')->first();
        if (empty($user)) {
            abort(500, 'Invalid Request');
        } else if (!empty($user->subscription) && $user->subscription->status !== 'canceled') {
            abort(204, 'Already subscribe');
        }

        $customerId = self::createCustomer($user);

        $data = $stripe->checkout->sessions->create([
            'success_url' => config('app.contractor_url') . 'subscription/success',
            'cancel_url' => config('app.contractor_url'),
            'line_items' => [
                [
                    'price' => $price_id,
                    'quantity' => 1,
                ],
            ],
            'mode' => 'subscription',
            'payment_method_types' => ['card'],
            "customer" => $customerId,
        ]);

        return $data->url;
    }

    public static  function createSubscription($data)
    {
        $stripe =  new \Stripe\StripeClient(config('app.stripe_secret'));
        $subscription = $stripe->subscriptions->retrieve($data['subscription'] ?? $data['id'], [])->toArray();
        $product = $stripe->products->retrieve($subscription['items']['data'][0]['plan']['product'], [])->toArray();
        $customer = $stripe->customers->retrieve($subscription['customer'], [])->toArray();
        $user = User::where('email', $customer['email'])->first();

        $teamUsers =  1;
        if ($product['name'] === 'Team') {
            $teamUsers =  5;
        } else if ($product['name'] === 'Business') {
            $teamUsers =  8;
        }

        $sub = Subscription::updateOrCreate(
            [
                'user_id' => !empty($user) ? $user->id : null,
                // 'customer_id' => $data['customer'] ?? $customer['id'],
            ],
            [
                'user_id' => !empty($user) ? $user->id : null,
                'customer_id' => $data['customer'] ?? $customer['id'],
                'product_id' => $subscription['items']['data'][0]['plan']['product'],
                'price_id' => $subscription['items']['data'][0]['plan']['id'],
                'subscription_id' => $subscription['id'],
                'subscription_item_id' => $subscription['items']['data'][0]['id'],
                'plan_name' => $product['name'],
                'plan_interval' => $subscription['items']['data'][0]['plan']['interval'] === 'month' ? 'monthly' : 'yearly',
                'plan_price' => $subscription['items']['data'][0]['plan']['amount'] / 100,
                'team_users' => $teamUsers,
                'status' => $subscription['status'],
                'started_at' => Carbon::parse($subscription['current_period_start'])->format('Y-m-d H:i:s'),
                'expired_at' => Carbon::parse($subscription['current_period_end'])->format('Y-m-d H:i:s'),
                'cancel_at' => $subscription['cancel_at'] ? Carbon::parse($subscription['cancel_at'])->format('Y-m-d H:i:s') : null
            ]
        );

        if ($user->status === 'I' && $user->is_first_time_login === 'YES') {
            $user->update(['status' => 'A']);
        }

        if ($sub->wasRecentlyCreated) {
            SendEmail::dispatchNow('UserSubscribe', $user->email, $user);
            if ($sub->plan_name === 'Enterprise') {
                SendEmail::dispatchNow('InformUserCheckout', config('app.admin_mail'), $user);
            }

            if ($sub->plan_name === 'Solo') {
                $crews = Crew::where('contractor_id', $user->id)->wherehas('roles')->with('crewUser')->get();
                if (!empty($crews)) {
                    $rolesId = Role::where('contractor_id', $user->id)->pluck('id')->toArray();
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
            } else if ($sub->plan_name === 'Team') {
                $crews = Crew::where('contractor_id', $user->id)->wherehas('roles')->with('crewUser')->skip(5)->orderBy('crew_id', 'asc')->get();
                if (!empty($crews)) {
                    $rolesId = Role::where('contractor_id', $user->id)->pluck('id')->toArray();
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
            } else if ($sub->plan_name === 'Business') {
                $crews = Crew::where('contractor_id', $user->id)->wherehas('roles')->with('crewUser')->skip(8)->orderBy('crew_id', 'asc')->get();
                if (!empty($crews)) {
                    $rolesId = Role::where('contractor_id', $user->id)->pluck('id')->toArray();
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
        }
    }

    public static function getPaymentIntent($id, $account_id)
    {
        $stripe =  new \Stripe\StripeClient(config('app.stripe_secret'));
        return $stripe->paymentIntents->retrieve($id, [], ['stripe_account' => $account_id])->toArray();
    }
}

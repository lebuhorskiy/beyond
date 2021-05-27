<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Education\Program;
use App\Models\Education\ProgramToCoach;
use App\Models\Pay\Pay;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use YandexCheckout\Client;

class PayController extends Controller
{

    /**
     * Create first pay api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function makePayRequest(Request $request): ?JsonResponse
    {
        // Тип оплаты
        $type = $request->post('type', 'bank_card');

        // Если оплата WagerPoints
        $is_wager = $request->post('is_wager', false);
        // Сумма пополнения
        $points_amount = $request->post('points_amount');

        if (!$is_wager) {
            $bp_points_package = DB::table('bp_points_packages')
                ->where('uuid', '=', $request->post('product_uuid'))
                ->first();

            if (!$bp_points_package) {
                return response()->json([
                    'status_message' => __('pay.error_get_bp_points_packages_not_found'),
                    'success' => false,
                ], $this->notFoundStatus);
            }
        }

        $user = DB::table('users')
            ->select('id', 'uuid', 'phone', 'nickname')
            ->where('uuid', '=', $request->post('user_uuid'))
            ->first();

        if (!$user) {
            return response()->json([
                'status_message' => __('pay.error_create_payment_user_not_found'),
                'success' => false,
            ], $this->notFoundStatus);
        }

        $client = new Client();
        $client->setAuth(env('YANDEX_PAY_SHOP_ID'), env('YANDEX_PAY_SECRET_KEY'));

        if (!$is_wager) {
            $value = $bp_points_package->amount * 0.20;
            if ($bp_points_package->discount) {
                $value = ($bp_points_package->amount * 0.20) - (($bp_points_package->amount * 0.20) / 100 * $bp_points_package->discount);
            }
            $pay_description = __('pay.pay_description', ['b_points' => $bp_points_package->amount]);
        } else {
            $value = $points_amount * 0.20;
            $pay_description = __('pay.pay_wager_description', ['w_points' => $points_amount]);
        }

        $payment_data = array(
            'amount' => array(
                'value' => $value,
                'currency' => 'RUB',
            ),
            'confirmation' => array(
                'type' => 'embedded'
            ),
            "receipt" => array(
                "customer" => array(
                    "full_name" => $user->nickname,
                    "phone" => $user->phone ?? '+79031234567',
                ),
                "items" => array(
                    array(
                        "description" => $pay_description,
                        "quantity" => "1.00",
                        "amount" => array(
                            "value" => $value,
                            "currency" => "RUB"
                        ),
                        "vat_code" => "1",
                        "payment_mode" => "full_payment",
                        "payment_subject" => "service"
                    ),
                ),
            ),
            'capture' => true,
            'description' => $pay_description,
        );

        if ($type === 'qiwi') {
            $payment_data[] = ['payment_method_data' => [
                'type' => $type,
                'phone' => $user->phone ?? '+79031234567',
            ],];
        }

        $payment = $client->createPayment(
            $payment_data,
            uniqid('', true)
        );

        if (!isset($payment['id'])) {
            return response()->json([
                'status_message' => __('pay.error_create_payment'),
                'debug' => json_encode($payment),
                'success' => false,
            ], $this->errorStatus);
        }

        if (!$is_wager) {
            $product_id = $bp_points_package->id;
        } else {
            $product_id = null;
        }

        DB::table('pay')
            ->insert([
                'wager_points' => $points_amount,
                'is_wager' => $is_wager,
                'payment_created_at' => Carbon::now(),
                'payment_expires_at' => Carbon::now(),
                'uuid' => $payment['id'],
                'pay_id' => $payment['id'],
                'payment_method' => $type,
                'payment_method_id' => '778ba82c-11b2-3134-86f9-053ab78b9bc8',
                'status' => $payment['status'],
                'paid' => $payment['paid'],
                'test' => false,
                'price' => $payment['amount']['value'],
                'product_id' => $product_id,
                'user_id' => $user->id,
            ]);
        return response()->json([
            'user' => $user,
            'bpoints' => $bp_points_package ?? null,
            'wpoints' => $pay_description ?? null,
            'data' => $payment,
            'success' => true,
        ]);

    }

    /**
     * Make payment for Education Unit
     *
     * @param Request $request
     * @return JsonResponse|null
     */
    public function makeEducationPayRequest(Request $request): ?JsonResponse
    {
        // Тип оплаты
        $type = $request->post('type', 'bank_card');
        $coach_id = $request->post('coach_id', null);
        $program_id = $request->post('program_id', null);
        $course_id = $request->post('course_id', null);
        $value = 0;
        $pay_description = '';

        // For coach programs
        if ($program_id && $coach_id) {
            $data = ProgramToCoach::with('program', 'coach')->where('program_id', '=', $program_id)
                ->where('coach_id', '=', $coach_id)
                ->first();
            $value = $data->prices['rub'];
            if ($data->program->subtitle) {
                $pay_description = 'Оплата пакета тренировок "' . $data->program->title . '" (' . $data->program->subtitle['ru'] . ') от ' . $data->coach->person->full_name;
            } else {
                $pay_description = 'Оплата пакета тренировок "' . $data->program->title . '" от ' . $data->coach->person->full_name;
            }
        }
        if ($program_id && !$coach_id) {
            $data = Program::find($program_id);
            $value = 25000;
            $pay_description = 'Оплата курса "' . $data->title . '"';
        }

        $client = new Client();
        $client->setAuth(env('YANDEX_PAY_SHOP_ID'), env('YANDEX_PAY_SECRET_KEY'));


        $payment_data = array(
            'amount' => array(
                'value' => $value,
                'currency' => 'RUB',
            ),
            'confirmation' => array(
                'type' => 'embedded'
            ),
            "receipt" => array(
                "customer" => array(
                    "full_name" => auth()->user()->nickname,
                    "phone" => auth()->user()->phone ?? '+79031234567',
                ),
                "items" => array(
                    array(
                        "description" => $pay_description,
                        "quantity" => "1.00",
                        "amount" => array(
                            "value" => $value,
                            "currency" => "RUB"
                        ),
                        "vat_code" => "1",
                        "payment_mode" => "full_payment",
                        "payment_subject" => "service"
                    ),
                ),
            ),
            'capture' => true,
            'description' => $pay_description,
        );

        if ($type === 'qiwi') {
            $payment_data[] = ['payment_method_data' => [
                'type' => $type,
                'phone' => $user->phone ?? '+79031234567',
            ],];
        }

        $payment = $client->createPayment(
            $payment_data,
            uniqid('', true)
        );


        if (!isset($payment['id'])) {
            return response()->json([
                'status_message' => __('pay.error_create_payment'),
                'debug' => json_encode($payment),
                'success' => false,
            ], $this->errorStatus);
        }

        DB::table('education_pay_to_users')
            ->insert([
                'uuid' => $payment['id'],
                'status' => $payment['status'],
                'paid' => $payment['paid'],
                'price' => $payment['amount']['value'],
                'program_id' => $program_id,
                'coach_id' => $coach_id,
                'course_id' => $course_id,
                'user_id' => auth()->id(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        return response()->json([
            'data' => $payment
        ]);
    }

    /**
     * Get bpoints packages api
     *
     * @return JsonResponse
     */
    public function getBPointsPackageRequest(): ?JsonResponse
    {
        $data = DB::table('bp_points_packages')
            ->get();
        if (!$data) {
            return response()->json([
                'status_message' => '',
                'success' => false,
            ], 404);
        }
        return response()->json([
            'data' => $data,
            'success' => true,
        ]);
    }

    /**
     * Get payment status by uuid api
     *
     * @param string $uuid
     * @return JsonResponse
     */
    public function getPaymentStatusRequest(string $uuid): ?JsonResponse
    {
        $is_pay_table = DB::table('pay')
            ->where('uuid', '=', [$uuid])
            ->first();

        if ($is_pay_table) {
            $data = DB::table('pay')
                ->where('uuid', '=', [$uuid])
                ->first();
        } else {
            $data = DB::table('education_pay_to_users')
                ->where('uuid', '=', [$uuid])
                ->first();
        }

        if (!$data) {
            return response()->json([
                'status_message' => __('pay.error_get_payment_status_payment_not_found'),
                'success' => false,
            ], $this->notFoundStatus);
        }

        if ($is_pay_table) {
            if ($data->is_wager) {
                return response()->json([
                    'data' => $data,
                    'wager_points' => auth('api')->user()->wager_points,
                    'redirect_to' => false,
                    'success' => true,
                ]);
            }
            return response()->json([
                'data' => $data,
                'balance_points' => auth('api')->user()->balance_points,
                'redirect_to' => false,
                'success' => true,
            ]);
        }
        return response()->json([
            'data' => $data,
            'redirect_to' => true,
            'success' => true,
        ]);
    }

    /**
     * Set payment status (webhook)
     *
     * @return Response
     */
    public function setPaymentStatusRequest(): ?Response
    {
        $response = file_get_contents('php://input');
        $data = json_decode($response, true);

        if (isset($data['object']['id'])) {
            $is_pay_table = Pay::with('product')->where('pay_id', '=', $data['object']['id'])->first();

            if ($is_pay_table) {

                $pay = $is_pay_table;

                DB::table('pay')
                    ->where('pay_id', '=', $data['object']['id'])
                    ->update([
                        'paid' => $data['object']['paid'],
                        'status' => $data['object']['status'],
                    ]);
            } else {
                $pay = DB::table('education_pay_to_users')
                    ->where('uuid', '=', $data['object']['id']);

                $pay->update([
                    'paid' => $data['object']['paid'],
                    'status' => $data['object']['status'],
                    'updated_at' => Carbon::now()
                ]);
            }

            if ($data['object']['status'] == 'succeeded') {

                if ($is_pay_table) {
                    if ($pay->is_wager) {
                        $current_balance = DB::table('users')
                            ->where('id', '=', $pay->user_id)->value('wager_points');
                        DB::table('users')
                            ->where('id', '=', $pay->user_id)
                            ->update(
                                [
                                    'wager_points' => ($current_balance + $pay->wager_points),
                                ]
                            );
                    } else {
                        $current_balance = DB::table('users')
                            ->where('id', '=', $pay->user_id)->value('balance_points');
                        DB::table('users')
                            ->where('id', '=', $pay->user_id)
                            ->update(
                                [
                                    'balance_points' => ($current_balance + $pay->product->amount),
                                ]
                            );
                    }
                }

            }
            return response('success');
        }

        return response('error', $this->errorStatus);

    }

    /**
     * Check payment status by uuid api
     *
     * @param string $uuid
     * @return Response|null
     */
    public function checkPaymentStatusRequest(string $uuid): ?Response
    {
        return null;
    }

}
